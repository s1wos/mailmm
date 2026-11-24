<?php

namespace App\Jobs;

use App\Exceptions\ForwardingFailure;
use App\Models\Email;
use App\Models\MailboxForwardingRule;
use App\Services\ForwardingMime;
use App\Services\ForwardingRequests;
use App\Services\ForwardingTransport;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendForwardingVerification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $ruleId, public readonly int $version, public readonly string $code)
    {
        $this->onConnection('forwarding')->onQueue('forwarding');
    }

    public function handle(ForwardingTransport $transport): void
    {
        $rule = MailboxForwardingRule::where('version', $this->version)->where('status', 'pending')->find($this->ruleId);
        $since = ForwardingRequests::automaticSince();
        if (! $since || ! $rule || ! $rule->code_expires_at?->isFuture() || ! $rule->code_hash || $rule->code_attempts >= 5
            || $rule->code_expires_at->subMinutes(10)->lt($since)
            || ! hash_equals($rule->code_hash, ForwardingRequests::codeHash($rule, $this->code))
            || ! Email::activePermanent()->whereKey($rule->email_id)->exists() || ! ForwardingRequests::isExternal($rule->destination)) {
            return;
        }
        try {
            $mail = ForwardingMime::verification($rule->destination, $this->code);
            $transport->send('manual', $mail, $rule->destination, (string) config('forwarding.service_address'));
        } catch (ForwardingFailure $failure) {
            MailboxForwardingRule::whereKey($rule->id)->where('version', $this->version)->where('status', 'pending')
                ->where('code_hash', $rule->code_hash)->update(['last_error_code' => $failure->getMessage()]);
        }
    }
}
