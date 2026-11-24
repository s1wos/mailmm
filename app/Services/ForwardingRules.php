<?php

namespace App\Services;

use App\Jobs\SendForwardingVerification;
use App\Models\Email;
use App\Models\MailboxForwardingRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

final class ForwardingRules
{
    public static function forPanel(User $user): ?array
    {
        if ($user->is_admin) {
            return null;
        }
        $box = $user->mailbox()->activePermanent()->first();
        if (! $box) {
            return null;
        }

        return self::rule($box)->panelData();
    }

    private static function ownerMailbox(User $actor): Email
    {
        $user = ForwardingRequests::lockActor($actor);
        abort_if($user->is_admin, 403);
        $email = $user->mailbox()->activePermanent()->lockForUpdate()->first();
        abort_unless($email, 403);

        return $email;
    }

    private static function rule(Email $box, bool $lock = false): MailboxForwardingRule
    {
        $query = MailboxForwardingRule::where('email_id', $box->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? new MailboxForwardingRule(['email_id' => $box->id, 'status' => 'disabled', 'version' => 0]);
    }

    public static function register(User $actor, string $destination, string $password): MailboxForwardingRule
    {
        return DB::transaction(function () use ($actor, $destination, $password) {
            $email = self::ownerMailbox($actor);
            if (! Hash::check($password, $actor->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
            }
            self::assertExternal($destination);
            $rule = self::rule($email, lock: true);
            $sendingEnabled = ForwardingRequests::automaticSince() !== null;
            if ($sendingEnabled && $rule->status === 'pending' && $rule->destination === $destination && $rule->resend_at?->isFuture()) {
                ForwardingRequests::rejectQuota($rule->resend_at->timestamp - now()->timestamp);
            }
            if ($rule->exists) {
                ForwardingRequests::cancelRuleJobs($rule, 'rule_replaced');
            }
            $rule->fill(['destination' => $destination, 'version' => $rule->version + 1, 'status' => 'pending',
                'activated_at' => null, 'last_error_code' => null, 'disabled_reason' => null]);
            if (! $sendingEnabled) {
                $rule->fill(['code_hash' => null, 'code_expires_at' => null, 'resend_at' => null, 'code_attempts' => 0])->save();

                return $rule;
            }

            return self::issue($rule);
        }, attempts: 3);
    }

    private static function issue(MailboxForwardingRule $rule): MailboxForwardingRule
    {
        if (! ForwardingQuota::reserve('verification', $rule->email_id, $rule->destination, 1024)) {
            ForwardingRequests::rejectQuota();
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $rule->fill(['code_hash' => ForwardingRequests::codeHash($rule, $code), 'code_attempts' => 0,
            'code_expires_at' => now()->addMinutes(10), 'resend_at' => now()->addMinute()])->save();
        Queue::connection('forwarding')->pushOn('forwarding', new SendForwardingVerification($rule->id, $rule->version, $code));

        return $rule;
    }

    private static function current(User $actor, int $version, bool $pending = true): MailboxForwardingRule
    {
        $rule = self::rule(self::ownerMailbox($actor), lock: true);
        if (! $rule->exists || $rule->version !== $version || ($pending && $rule->status !== 'pending')) {
            throw ValidationException::withMessages(['version' => 'Настройка пересылки изменилась. Обновите страницу.']);
        }

        return $rule;
    }

    private static function assertExternal(string $destination): void
    {
        if (! ForwardingRequests::isExternal($destination)) {
            throw ValidationException::withMessages(['to' => 'Для автопересылки укажите адрес за пределами MailMM.']);
        }
    }

    public static function resend(User $actor, int $version): MailboxForwardingRule
    {
        return DB::transaction(function () use ($actor, $version) {
            $rule = self::current($actor, $version);
            self::assertExternal($rule->destination);
            if ($rule->resend_at?->isFuture()) {
                ForwardingRequests::rejectQuota($rule->resend_at->timestamp - now()->timestamp);
            }

            return self::issue($rule);
        }, attempts: 3);
    }

    public static function verify(User $actor, int $version, string $code): MailboxForwardingRule
    {
        $result = DB::transaction(function () use ($actor, $version, $code) {
            $rule = self::current($actor, $version);
            self::assertExternal($rule->destination);
            if ($rule->code_hash === null || ! $rule->code_expires_at?->isFuture() || $rule->code_attempts >= 5) {
                return null;
            }
            if (! hash_equals($rule->code_hash, ForwardingRequests::codeHash($rule, $code))) {
                $rule->increment('code_attempts');

                return null;
            }
            $rule->update(['status' => 'active', 'activated_at' => now(), 'code_hash' => null, 'code_expires_at' => null,
                'resend_at' => null, 'code_attempts' => 0, 'last_error_code' => null]);

            return $rule;
        }, attempts: 3);

        // Commit failed attempts before returning the validation error.
        return $result ?? throw ValidationException::withMessages(['code' => 'Код неверен или истёк. Запросите новый код.']);
    }

    public static function disable(User $actor, int $version): MailboxForwardingRule
    {
        return DB::transaction(function () use ($actor, $version) {
            $rule = self::current($actor, $version, pending: false);
            ForwardingRequests::disableRule($rule, 'owner_disabled');

            return $rule;
        }, attempts: 3);
    }
}
