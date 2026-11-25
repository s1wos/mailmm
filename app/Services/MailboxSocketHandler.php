<?php

namespace App\Services;

use App\Http\Requests\MailboxSocketRequest;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use WeakMap;

class MailboxSocketHandler
{
    private WeakMap $subscriptions;

    private WeakMap $joined;

    public function __construct(private ChannelManager $channels, private MailboxSocketPool $pool)
    {
        $this->subscriptions = new WeakMap;
        $this->joined = new WeakMap;
    }

    public function sent(MessageSent $event): void
    {
        $frame = json_decode($event->message, true);
        $name = $frame['event'] ?? null;
        if (! in_array($name, ['pusher_internal:subscription_succeeded', 'message.received', 'mailbox.access-revoked'], true)) {
            return;
        }
        $member = $this->member($event->connection, $frame['channel'] ?? null);
        if (! $member) {
            return;
        }
        if ($name === 'mailbox.access-revoked') {
            unset($this->subscriptions[$member]);
        } elseif ($name === 'pusher_internal:subscription_succeeded') {
            $grant = $member->data('grant');
            if (! is_string($grant)) {
                return;
            }
            $joined = $this->joined[$event->connection] ?? [];
            $this->subscriptions[$member] = ['subscribed' => ! isset($joined[$grant]), 'deliveries' => [], 'seen' => [], 'pending' => false];
            $joined[$grant] = true;
            $this->joined[$event->connection] = array_slice($joined, -64, null, true);
        } elseif (isset($this->subscriptions[$member])) {
            $data = $frame['data'] ?? [];
            $delivery = is_string($data) ? json_decode($data, true) : $data;
            if (is_int($delivery['id'] ?? null)) {
                $ids = $this->subscriptions[$member]['deliveries'];
                $ids[$delivery['id']] = true;
                $this->subscriptions[$member]['deliveries'] = array_slice($ids, -64, null, true);
            }
        }
    }

    public function received(MessageReceived $event): void
    {
        $frame = json_decode($event->message, true);
        $command = $frame['event'] ?? null;
        $channel = $frame['channel'] ?? null;
        $data = $frame['data'] ?? null;
        $id = $data['requestId'] ?? null;
        if (! MailboxSocketRequest::hasValidEnvelope($command, $channel, $id)) {
            return;
        }
        $connection = $event->connection;
        $member = $this->member($connection, $channel);
        if (! $member || ! isset($this->subscriptions[$member])) {
            $this->send($connection, $channel, 'mailbox.error', ['requestId' => $id, 'code' => 'access_revoked']);

            return;
        }
        $state = &$this->subscriptions[$member];
        $seen = $state['seen'];
        if (isset($seen[$id]) || ! MailboxSocketRequest::hasValidPayload($command, $data)) {
            $this->send($connection, $channel, 'mailbox.error', ['requestId' => $id, 'code' => 'invalid_request']);

            return;
        }
        if ($state['pending']) {
            $this->send($connection, $channel, 'mailbox.error', ['requestId' => $id, 'code' => 'busy']);

            return;
        }
        $seen[$id] = true;
        $state['seen'] = array_slice($seen, -128, null, true);
        $state['pending'] = true;
        $automatic = false;
        $delivery = null;
        if ($command === 'mailbox.check' && $data['reason'] !== 'manual') {
            $delivery = $data['deliveryId'] ?? null;
            if ($delivery && isset($state['deliveries'][$delivery])) {
                unset($state['deliveries'][$delivery]);
                $automatic = true;
            } elseif ($state['subscribed'] && ! $delivery) {
                $state['subscribed'] = false;
                $automatic = true;
            }
        }
        $fields = array_intersect_key($data, array_flip(['reason', 'snapshot', 'requestId', 'continuation', 'deliveryId', 'delta']));
        $job = ['command' => $command, 'data' => $fields, 'socket' => $connection->id(), 'channel' => $channel,
            'grant' => $member->data('grant'), 'automatic' => $automatic];
        $reply = function (array $result) use ($connection, $channel, $id, $member, $automatic, $delivery) {
            if (! isset($this->subscriptions[$member]) || $this->member($connection, $channel) !== $member) {
                return;
            }
            $this->subscriptions[$member]['pending'] = false;
            if ($member->data('grantExpiresAt') <= now()->toISOString()) {
                $this->send($connection, $channel, 'mailbox.error', ['requestId' => $id, 'code' => 'access_revoked']);

                return;
            }
            if ($automatic && in_array($result['code'] ?? null, ['busy', 'timeout', 'unavailable'], true)) {
                if ($delivery) {
                    $this->subscriptions[$member]['deliveries'][$delivery] = true;
                } else {
                    $this->subscriptions[$member]['subscribed'] = true;
                }
            }
            $result['requestId'] = $id;
            $this->reply($connection, $channel, $result);
        };
        $this->pool->submit($job, fn (array $result) => $member->afterAccessChange(fn () => $reply($result)));
    }

    private function member(Connection $connection, mixed $channel): ?MailboxChannelMember
    {
        if (! MailboxChannelConnections::isMailboxChannel($channel)) {
            return null;
        }

        $member = $this->channels->for($connection->app())->find($channel)?->find($connection);

        return $member instanceof MailboxChannelMember ? $member : null;
    }

    private function reply(Connection $connection, string $channel, array $result): void
    {
        if (isset($result['code'])) {
            $this->send($connection, $channel, 'mailbox.error', $result);

            return;
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) <= 32768) {
            $this->send($connection, $channel, 'mailbox.checked', $result);

            return;
        }
        $parts = [];
        for ($offset = 0, $length = strlen($json); $offset < $length; $offset += strlen($part)) {
            $part = mb_strcut($json, $offset, 8192, 'UTF-8');
            $parts[] = $part;
        }
        foreach ($parts as $index => $content) {
            $this->send($connection, $channel, 'mailbox.chunk', [
                'requestId' => $result['requestId'], 'index' => $index, 'total' => count($parts), 'content' => $content,
            ]);
        }
    }

    private function send(Connection $connection, string $channel, string $event, array $data): void
    {
        $connection->send(json_encode(compact('event', 'channel', 'data'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
