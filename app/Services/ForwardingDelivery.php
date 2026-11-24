<?php

namespace App\Services;

use App\Exceptions\ForwardingCheckFailed;
use App\Exceptions\ForwardingFailure;
use App\Jobs\SendMailForward;
use App\Models\Email;
use App\Models\MailboxForwardingRule;
use App\Models\MailForward;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;

class ForwardingDelivery
{
    public function __construct(private ForwardingTransport $transport, private ForwardingIngress $ingress) {}

    public function send(string $id): void
    {
        $attempt = (string) Str::uuid();
        $claim = $this->withEligible($id, 'queued', null, function ($op, $message) use ($attempt) {
            $op->update(['status' => 'preparing', 'attempt_id' => $attempt, 'attempts' => $op->attempts + 1, 'next_attempt_at' => null]);

            $raw = $this->readOriginal($message);
            if ($raw === null && $op->mode !== 'manual') {
                throw new ForwardingFailure('original_unavailable');
            }

            return [$op, $message, $raw];
        });
        if (! $claim) {
            return;
        }
        [$op, $message, $raw] = $claim;
        try {
            if ($op->mode === 'auto') {
                $mail = ForwardingMime::automatic($raw, $id, $this->ingress->authenticationResults($message, $raw));
            } else {
                $context = $message->forwarding_context;
                if (isset($context['blocked_reason'])) {
                    throw new ForwardingFailure($context['blocked_reason']);
                }
                if (! empty($context['manual_check_deferred'])) {
                    if ($raw === null) {
                        throw new ForwardingFailure('ingress_unavailable');
                    }
                    // Recheck the filter, but do not present today's DNS as evidence from the original delivery.
                    $this->ingress->authenticationResults($message, $raw);
                }
                // Reconstructed bodies and historical SMTP sessions cannot attest to the original author's authentication.
                $authentication = $raw !== null ? ($context['authentication_results'] ?? null) : null;
                $mail = ForwardingMime::manual($raw ?? ForwardingArchive::mime($message, $id, $op->destination),
                    $id, $authentication ?? config('forwarding.authserv_id').'; none');
            }
            $sender = ForwardingFeedback::address($id);
        } catch (ForwardingCheckFailed $failure) {
            $this->finish($op, 'preparing', new ForwardingFailure($failure->reason, $failure->temporary ? 'retry' : 'failed'));

            return;
        } catch (ForwardingFailure $failure) {
            $this->finish($op, 'preparing', $failure);

            return;
        }
        $started = $this->withEligible($id, 'preparing', $attempt, function ($locked) {
            return $locked->update(['status' => 'submitting', 'submitted_at' => now()]);
        });
        if (! $started) {
            return;
        }
        try {
            $this->transport->send('auto', $mail, $op->destination, $sender);
            $result = null;
        } catch (ForwardingFailure $failure) {
            $result = $failure;
        }
        $this->finish($op, 'submitting', $result);
    }

    private function readOriginal(Message $message): ?string
    {
        if (! $message->raw_path || ! $message->raw_sha256 || $message->raw_size === null) {
            return null;
        }
        if ($message->raw_size > (int) config('forwarding.max_bytes', 33554432)) {
            throw new ForwardingFailure('message_too_large');
        }
        try {
            $stream = Storage::disk('local')->readStream($message->raw_path);
        } catch (UnableToReadFile) {
            return null;
        }
        if (! is_resource($stream)) {
            return null;
        }
        try {
            $raw = stream_get_contents($stream, (int) $message->raw_size + 1);
        } finally {
            fclose($stream);
        }
        if ($raw === false || strlen($raw) !== (int) $message->raw_size || ! hash_equals($message->raw_sha256, hash('sha256', $raw))) {
            throw new ForwardingFailure('original_corrupt');
        }

        return $raw;
    }

    /** Serialize the two claims with pruning, changing a rule, closing a mailbox and revoking credentials. */
    private function withEligible(string $id, string $status, ?string $attempt, Closure $callback): mixed
    {
        return DB::transaction(function () use ($id, $status, $attempt, $callback) {
            $hint = MailForward::where('status', $status)->find($id);
            if (! $hint) {
                return null;
            }
            $user = $hint->initiator_id ? User::lockForUpdate()->find($hint->initiator_id) : null;
            $message = Message::retained()->lockForUpdate()->find($hint->message_id);
            $box = $message?->email_id ? Email::activePermanent()->lockForUpdate()->find($message->email_id) : null;
            $rule = $hint->rule_id ? MailboxForwardingRule::lockForUpdate()->find($hint->rule_id) : null;
            $op = MailForward::where('status', $status)->where('attempt_id', $attempt)->lockForUpdate()->find($id);
            if (! $op || $op->next_attempt_at?->isFuture()) {
                return null;
            }
            try {
                $this->checkEligibility($op, $message, $box, $user, $rule);

                return $callback($op, $message);
            } catch (ForwardingFailure $failure) {
                $op->update(['status' => $failure->outcome, 'error_code' => $failure->getMessage()]);
                $op->logOutcome();
                if ($rule && $rule->version === $op->rule_version) {
                    $rule->update(['last_error_code' => $failure->getMessage()]);
                }

                return null;
            }
        }, 3);
    }

    private function checkEligibility(MailForward $op, ?Message $message, ?Email $box, ?User $user, ?MailboxForwardingRule $rule): void
    {
        if (! $message || $op->created_at->copy()->addDay()->isPast()) {
            throw new ForwardingFailure('delivery_expired');
        }
        if ($op->mode === 'manual') {
            $since = config('forwarding.manual_enabled_at');
            try {
                $enabled = config('forwarding.manual_enabled') && (! $since || $op->created_at->gte(CarbonImmutable::parse($since, 'UTC')));
            } catch (\InvalidArgumentException) {
                $enabled = false;
            }
            if (! $enabled) {
                throw new ForwardingFailure('mode_disabled', 'cancelled');
            }
            if (! $user || (! $user->is_admin && (! $box || $box->owner_user_id !== $user->id))) {
                throw new ForwardingFailure('access_revoked', 'cancelled');
            }
        } else {
            $since = ForwardingRequests::automaticSince();
            if (! $since || $message->received_at->lt($since)) {
                throw new ForwardingFailure('mode_disabled', 'cancelled');
            }
            if (! $box || ! $rule || $rule->email_id !== $box->id || $rule->status !== 'active' || $rule->version !== $op->rule_version
                || $rule->destination !== $op->destination || ! $rule->activated_at || $message->received_at->lt($rule->activated_at)) {
                throw new ForwardingFailure('rule_changed', 'cancelled');
            }
            if (! ForwardingRequests::isExternal($op->destination)) {
                throw new ForwardingFailure('destination_local', 'cancelled');
            }
        }
    }

    private function finish(MailForward $claim, string $expected, ?ForwardingFailure $failure): void
    {
        DB::transaction(function () use ($claim, $expected, $failure) {
            $rule = $claim->rule_id ? MailboxForwardingRule::lockForUpdate()->find($claim->rule_id) : null;
            $op = MailForward::where('status', $expected)->where('attempt_id', $claim->attempt_id)->lockForUpdate()->find($claim->id);
            if (! $op) {
                return;
            }
            $status = $failure?->outcome ?? 'accepted';
            $reason = $failure?->getMessage();
            $next = null;
            if ($status === 'retry' && $op->mode === 'manual' && in_array($op->error_code, ['password_reset', 'mailbox_closed'], true)) {
                $status = 'failed';
                $reason = $op->error_code;
            }
            if ($status === 'retry' && (! config('forwarding.'.$op->mode.'_enabled') || ($op->mode === 'auto'
                && (! $rule || $rule->status !== 'active' || $rule->version !== $op->rule_version)))) {
                $status = 'failed';
            }
            if ($status === 'retry') {
                $next = now()->startOfSecond()->addSeconds(min(3600, 60 * (2 ** min($op->attempts - 1, 6))));
                if ($next->gte($op->created_at->copy()->addDay())) {
                    $status = 'failed';
                    $reason = 'delivery_expired';
                    $next = null;
                } else {
                    $status = 'queued';
                    Bus::dispatch((new SendMailForward($op->id))->delay($next));
                }
            }
            $op->update(['status' => $status, 'error_code' => $reason, 'next_attempt_at' => $next,
                'attempt_id' => $status === 'queued' ? null : $op->attempt_id]);
            if ($rule && $rule->version === $op->rule_version && $rule->status === 'active') {
                if ($reason === 'recipient_rejected') {
                    ForwardingRequests::disableRule($rule, $reason);
                } elseif ($reason !== null) {
                    $rule->update(['last_error_code' => $reason]);
                }
            }
            $op->logOutcome();
        }, 3);
    }
}
