<?php

namespace App\Services;

use App\Jobs\SendMailForward;
use App\Models\Email;
use App\Models\MailboxDomain;
use App\Models\MailboxForwardingRule;
use App\Models\MailForward;
use App\Models\Message;
use App\Models\User;
use App\Rules\ForwardingAddress;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

final class ForwardingRequests
{
    public static function availability(Message $message): array
    {
        $enabled = (bool) config('forwarding.manual_enabled');

        return ['available' => $enabled, 'reason' => $enabled ? null : 'mode_disabled',
            'content' => $message->hasOriginal() ? 'original' : 'archive'];
    }

    public static function manual(User $actor, int $messageId, string $destination, string $requestId): MailForward
    {
        return DB::transaction(function () use ($actor, $messageId, $destination, $requestId) {
            $user = self::lockActor($actor);
            $message = PanelMessages::for($user)->lockForUpdate()->findOrFail($messageId);
            $existing = MailForward::where('initiator_id', $user->id)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->message_id !== $message->id || $existing->destination !== $destination) {
                    throw ValidationException::withMessages(['request_id' => 'Этот идентификатор уже использован для другой отправки.']);
                }

                return $existing;
            }
            if (! config('forwarding.manual_enabled')) {
                throw new HttpResponseException(response()->json(['code' => 'mode_disabled', 'message' => 'Ручная пересылка отключена.'], 422));
            }
            $bytes = $message->hasOriginal() ? $message->raw_size
                : strlen((string) $message->body_text) + strlen((string) $message->body_html)
                    + array_sum(array_column($message->attachments ?? [], 'size'));
            if (! ForwardingQuota::reserve('manual', $user->id, $destination, $bytes)) {
                self::rejectQuota();
            }
            $operation = MailForward::create(['message_id' => $message->id, 'mode' => 'manual', 'initiator_id' => $user->id,
                'request_id' => $requestId, 'destination' => $destination, 'status' => 'queued']);
            Queue::connection('forwarding')->pushOn('forwarding', new SendMailForward($operation->id));

            return $operation;
        }, attempts: 3);
    }

    public static function lockActor(User $actor): User
    {
        $user = User::lockForUpdate()->findOrFail($actor->id);
        abort_unless(hash_equals($user->getAuthPassword(), $actor->getAuthPassword()), 403);

        return $user;
    }

    public static function automaticSince(): ?CarbonImmutable
    {
        $since = config('forwarding.auto_enabled_at');
        if (! config('forwarding.auto_enabled') || ! is_string($since) || trim($since) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($since, 'UTC');
        } catch (\Exception) {
            return null;
        }
    }

    // Called under the locked message in the transaction that publishes its original.
    public static function automatic(Message $message, ?string $skipReason = null): bool
    {
        $since = self::automaticSince();
        if ($since === null || $message->is_pending || ! $message->hasOriginal() || ! $message->email_id ||
            $message->received_at->lt($since) || Message::expiresAt($message->received_at)->isPast()) {
            return false;
        }
        $email = Email::activePermanent()->whereKey($message->email_id)->lockForUpdate()->first();
        if (! $email) {
            return false;
        }
        $rule = MailboxForwardingRule::where('email_id', $email->id)->lockForUpdate()->first();
        if ($rule?->status !== 'active' || ! $rule->activated_at || $message->received_at->lt($rule->activated_at)
            || MailForward::where('message_id', $message->id)->where('mode', 'auto')->exists()) {
            return false;
        }
        if (! self::isExternal($rule->destination)) {
            self::disableRule($rule, 'destination_local');

            return false;
        }
        $when = null;
        if ($skipReason === null) {
            $when = ForwardingQuota::reserve('auto', $email->id, $rule->destination, $message->raw_size);
            if ($when === null) {
                $skipReason = 'quota_exceeded';
            }
        }
        $delayed = $when?->isFuture() ?? false;
        $operation = MailForward::create(['message_id' => $message->id, 'mode' => 'auto', 'rule_id' => $rule->id,
            'rule_version' => $rule->version, 'destination' => $rule->destination, 'status' => $skipReason ? 'failed' : 'queued',
            'error_code' => $skipReason, 'next_attempt_at' => $delayed ? $when : null]);
        if ($skipReason !== null) {
            $operation->logOutcome();
        }
        if ($skipReason !== null || $delayed) {
            $rule->update(['last_error_code' => $skipReason ?? 'quota_deferred']);
        }
        if ($skipReason === null) {
            $queue = Queue::connection('forwarding');
            $job = new SendMailForward($operation->id);
            if ($delayed) {
                $queue->laterOn('forwarding', $when, $job);
            } else {
                $queue->pushOn('forwarding', $job);
            }
        }

        return true;
    }

    public static function isExternal(string $address): bool
    {
        $normalized = ForwardingAddress::normalize($address);
        if ($normalized === null) {
            return false;
        }
        $domain = substr(strrchr($normalized, '@'), 1);

        return ! MailboxDomain::where('name', $domain)->exists()
            && $domain !== strtolower((string) config('forwarding.bounce_domain'));
    }

    public static function codeHash(MailboxForwardingRule $rule, string $code): string
    {
        $input = implode("\0", ['forwarding-code', $rule->email_id, $rule->destination, $rule->version, $code]);

        return hash_hmac('sha256', $input, config('app.key'));
    }

    public static function cancelRuleJobs(MailboxForwardingRule $rule, string $reason): void
    {
        MailForward::where('rule_id', $rule->id)->whereIn('status', ['queued', 'preparing'])
            ->update(['status' => 'cancelled', 'error_code' => $reason, 'attempt_id' => null, 'next_attempt_at' => null]);
    }

    // The caller holds the rule lock.
    public static function disableRule(MailboxForwardingRule $rule, string $reason): void
    {
        self::cancelRuleJobs($rule, $reason);
        $rule->update(['status' => 'disabled', 'version' => $rule->version + 1, 'disabled_reason' => $reason,
            'activated_at' => null, 'code_hash' => null, 'code_expires_at' => null, 'resend_at' => null, 'code_attempts' => 0]);
    }

    public static function revokeMailbox(Email $email, int $ownerId, string $reason): void
    {
        $rule = MailboxForwardingRule::where('email_id', $email->id)->lockForUpdate()->first();
        if ($rule) {
            self::disableRule($rule, $reason);
        }
        // Keep the current SMTP attempt and its recovery deadline, but revoke permission to retry it.
        MailForward::where('initiator_id', $ownerId)->where('status', 'submitting')->toBase()->update(['error_code' => $reason]);
        MailForward::where('initiator_id', $ownerId)->whereIn('status', ['queued', 'preparing'])
            ->update(['status' => 'cancelled', 'error_code' => $reason, 'attempt_id' => null, 'next_attempt_at' => null]);
    }

    public static function rejectQuota(?int $retryAfter = null): never
    {
        $retryAfter ??= now()->endOfHour()->timestamp - now()->timestamp + 1;
        throw new HttpResponseException(response()->json(['code' => 'quota_exceeded', 'message' => 'Лимит отправок исчерпан. Попробуйте позже.'],
            429, ['Retry-After' => (string) max(1, $retryAfter)]));
    }
}
