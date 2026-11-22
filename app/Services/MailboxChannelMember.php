<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

class MailboxChannelMember extends ChannelConnection
{
    private bool $confirmed = false;

    private bool $awaitingRevocation = false;

    private bool $checkingBroadcast = false;

    private ?string $latestDelivery = null;

    private ?string $revocation = null;

    private bool $checkingAccessChange = false;

    private ?Closure $pendingReply = null;

    private ?TimerInterface $timer = null;

    public function __construct(Connection $connection, array $data, private string $channel)
    {
        parent::__construct($connection, $data);
    }

    public function confirm(Closure $acknowledge, Closure $denied): void
    {
        $this->check(function (bool $authorized) use ($acknowledge, $denied) {
            if ($authorized) {
                $this->confirmed = true;
                $acknowledge();
            } else {
                $this->currentChannel()?->unsubscribe($this->connection);
                $denied();
            }
        });
    }

    public function send(string $message): void
    {
        if (! $this->confirmed) {
            return;
        }
        $event = json_decode($message, true)['event'] ?? null;
        if ($this->awaitingRevocation) {
            if ($event === 'mailbox.access-revoked') {
                $this->denyBroadcast($message);
            }

            return;
        }
        if ($event === 'mailbox.access-revoked') {
            $this->checkingAccessChange = true;
            $this->revocation = $message;
        } elseif ($event === 'message.received') {
            $this->latestDelivery = $message;
        } else {
            $this->check(function (bool $authorized) use ($message) {
                if ($authorized) {
                    parent::send($message);
                } else {
                    $this->denyBroadcast($this->revocation);
                }
            }, retryBusy: false);

            return;
        }
        if (! $this->checkingBroadcast) {
            $this->flushBroadcast();
        }
    }

    private function flushBroadcast(): void
    {
        $this->checkingBroadcast = true;
        $delivery = $this->latestDelivery;
        $revocation = $this->revocation;
        $this->latestDelivery = $this->revocation = null;
        $this->check(function (bool $authorized) use ($delivery, $revocation) {
            $this->checkingBroadcast = false;
            if (! $authorized) {
                $this->denyBroadcast($revocation ?? $this->revocation);

                return;
            }
            if ($revocation !== null && $this->revocation === null) {
                $this->checkingAccessChange = false;
                $reply = $this->pendingReply;
                $this->pendingReply = null;
                $reply?->__invoke();
            }
            if ($this->revocation !== null) {
                $this->latestDelivery ??= $delivery;
            } elseif ($delivery !== null) {
                parent::send($delivery);
            }
            if ($this->latestDelivery !== null || $this->revocation !== null) {
                $this->flushBroadcast();
            }
        });
    }

    private function denyBroadcast(?string $revocation): void
    {
        $this->latestDelivery = $this->revocation = null;
        if ($revocation !== null || $this->expired()) {
            $this->currentChannel()?->unsubscribe($this->connection);
            if ($revocation !== null) {
                parent::send($revocation);
            }
        } elseif (! $this->awaitingRevocation) {
            $this->awaitingRevocation = true;
            $this->discardPendingWork();
            $seconds = now()->diffInSeconds($this->data('grantExpiresAt'));
            $this->timer = Loop::addTimer($seconds, fn () => $this->currentChannel()?->unsubscribe($this->connection));
        }
    }

    public function awaitingRevocation(): bool
    {
        return $this->awaitingRevocation;
    }

    public function afterAccessChange(Closure $reply): void
    {
        if ($this->awaitingRevocation || ! $this->currentChannel()) {
            return;
        }
        if ($this->checkingAccessChange) {
            $this->pendingReply = $reply;
        } else {
            $reply();
        }
    }

    public function discardPendingWork(): void
    {
        $this->pendingReply = null;
        if ($this->timer !== null) {
            Loop::cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    private function expired(): bool
    {
        return $this->data('grantExpiresAt') <= now()->toISOString();
    }

    private function currentChannel(): ?Channel
    {
        $channel = app(ChannelManager::class)->for($this->connection->app())->find($this->channel);

        return ($channel?->connections()[$this->connection->id()] ?? null) === $this ? $channel : null;
    }

    private function check(Closure $complete, bool $retryBusy = true, int $retries = 0): void
    {
        if (! $this->currentChannel()) {
            return;
        }
        if ($this->awaitingRevocation || ! is_string($this->data('grant')) || $this->expired()) {
            $complete(false);

            return;
        }
        app(MailboxSocketPool::class)->submit([
            'command' => 'mailbox.authorize', 'grant' => $this->data('grant'),
            'socket' => $this->connection->id(), 'channel' => $this->channel,
        ], function (array $result) use ($complete, $retryBusy, $retries) {
            $channel = $this->currentChannel();
            if (! $channel) {
                return;
            }
            if ($this->awaitingRevocation || $this->expired() || ($result['code'] ?? null) === 'access_revoked') {
                $complete(false);
            } elseif ($result['authorized'] ?? false) {
                $complete(true);
            } elseif ($retryBusy && ($result['code'] ?? null) === 'busy' && $retries < 6) {
                $this->timer = Loop::addTimer(0.1 * 2 ** $retries, function () use ($complete, $retries) {
                    $this->timer = null;
                    $this->check($complete, retries: $retries + 1);
                });
            } else {
                Log::warning('Mailbox WebSocket authorization failed. Reconnecting for a fresh snapshot.', [
                    'reason' => $result['code'] ?? 'unavailable', 'retries' => $retries,
                ]);
                $channel->unsubscribe($this->connection);
                $this->connection->terminate();
            }
        });
    }
}
