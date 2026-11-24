<?php

namespace Tests\Feature;

use App\Exceptions\ForwardingFailure;
use App\Jobs\SendMailForward;
use App\Models\MailboxForwardingRule;
use App\Models\MailForward;
use App\Models\Message;
use App\Models\User;
use App\Services\ForwardingDelivery;
use App\Services\ForwardingFeedback;
use App\Services\ForwardingIngress;
use App\Services\ForwardingMarker;
use App\Services\ForwardingTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

class ForwardingDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Message $message;

    private User $owner;

    private const RAW = "From: Shop <shop@sender.test>\r\nTo: owner@mailmm.test\r\nContent-Type: text/html\r\n\r\n<b>Receipt</b>\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['forwarding.manual_enabled' => true, 'forwarding.auto_enabled' => true,
            'forwarding.auto_enabled_at' => now()->subDay()->toISOString(), 'forwarding.bounce_domain' => 'bounce.test',
            'forwarding.service_address' => 'service@mailmm.test', 'forwarding.authserv_id' => 'mx.mailmm.test']);
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $box = $this->owner->mailbox()->create(['email' => 'owner@mailmm.test']);
        $this->message = Message::create(['email_id' => $box->id, 'recipient' => $box->email, 'from' => 'shop@sender.test',
            'subject' => 'Receipt', 'received_at' => now(), 'raw_path' => 'test.eml', 'raw_size' => strlen(self::RAW),
            'raw_sha256' => hash('sha256', self::RAW), 'is_pending' => false, 'forwarding_context' => ['sender' => 'shop@sender.test',
                'helo' => 'sender.test', 'ip' => '127.0.0.1', 'authentication_results' => 'mx.mailmm.test; dkim=pass']]);
        Storage::disk('local')->put('test.eml', self::RAW);
    }

    private function operation(string $mode = 'manual'): MailForward
    {
        $fields = ['initiator_id' => $this->owner->id];
        if ($mode === 'auto') {
            $rule = MailboxForwardingRule::create(['email_id' => $this->message->email_id, 'version' => 1, 'status' => 'active',
                'destination' => 'reader@outside.test', 'activated_at' => now()->subHour()]);
            $fields = ['rule_id' => $rule->id, 'rule_version' => 1];
        }

        return MailForward::create($fields + ['mode' => $mode, 'message_id' => $this->message->id, 'destination' => 'reader@outside.test',
            'status' => 'queued']);
    }

    private function manualWire(): string
    {
        $op = $this->operation();
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andReturnUsing(function ($profile, $message) use (&$wire) {
            $wire = $message->toString();
        });
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame('accepted', $op->fresh()->status);

        return $wire;
    }

    public static function manualInitiators(): array
    {
        return ['mailbox owner' => [false], 'archive administrator' => [true]];
    }

    #[DataProvider('manualInitiators')]
    public function test_manual_is_claimed_once_and_source_and_envelope_are_separate(bool $asAdmin): void
    {
        $actor = $asAdmin ? User::factory()->create(['is_admin' => true]) : $this->owner;
        $id = $this->actingAs($actor)->postJson("/admin/messages/{$this->message->id}/forwards", [
            'request_id' => (string) Str::uuid(), 'to' => 'reader@outside.test',
        ])->assertAccepted()->json('forward.id');
        $op = MailForward::findOrFail($id);
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andReturnUsing(function ($profile, $mail, $target, $bounce) use ($op, &$submission) {
            $submission = [$profile, $target, $bounce, $op->fresh()->status, $mail->toString()];
        });
        $worker = app(ForwardingDelivery::class);
        $worker->send($op->id);
        $worker->send($op->id);
        $this->assertSame('accepted', $op->fresh()->status);
        $this->assertSame(1, $op->fresh()->attempts);
        [$profile, $target, $bounce, $status, $wire] = $submission;
        $this->assertSame('auto', $profile);
        $this->assertSame('reader@outside.test', $target);
        $this->assertSame(ForwardingFeedback::address($op->id), $bounce);
        $this->assertSame('submitting', $status);
        $this->assertSame('manual', ForwardingMarker::type($wire));
        $parsed = (new MailMimeParser)->parse($wire, false);
        $this->assertSame('shop@sender.test', $parsed->getHeader('From')->getEmail());
        $this->assertSame('Shop', $parsed->getHeader('From')->getPersonName());
        $this->assertSame('owner@mailmm.test', $parsed->getHeader('To')->getEmail());
        $this->assertStringContainsString("Authentication-Results: mx.mailmm.test; dkim=pass\r\n", $wire);
    }

    public static function evidence(): array
    {
        return [['corrupt', 'original_corrupt'], ['revoked', 'access_revoked'], ['expired', 'delivery_expired']];
    }

    #[DataProvider('evidence')]
    public function test_worker_checks_source_and_current_authority_before_smtp(string $problem, string $reason): void
    {
        $op = $this->operation();
        match ($problem) {
            'corrupt' => Storage::disk('local')->put('test.eml', str_replace('From', 'Xrom', self::RAW)),
            'revoked' => DB::table('emails')->where('id', $this->message->email_id)->update(['closed_at' => now()]),
            'expired' => $op->forceFill(['created_at' => now()->subHours(25)])->save(),
        };
        $this->mock(ForwardingTransport::class)->shouldNotReceive('send');
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame($reason, $op->fresh()->error_code);
    }

    public function test_manual_archive_keeps_stored_html_text_and_legacy_binary_attachment(): void
    {
        $this->message->update(['raw_path' => null, 'raw_sha256' => null, 'raw_size' => null,
            'body_text' => 'Сохранённый текст', 'body_html' => '<p><b>Сохранённая вёрстка</b></p>']);
        $name = '2025/письма\чек.pdf';
        $path = "emails/{$this->message->id}/".$name;
        $bytes = "\0PDF\xff";
        Storage::disk('local')->put($path, $bytes);
        $this->message->update(['attachments' => [['filename' => $name, 'path' => $path,
            'mime' => 'application/pdf', 'size' => strlen($bytes)]]]);
        $op = $this->operation();
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andReturnUsing(function ($mode, $message) use ($bytes) {
            $wire = $message->toString();
            $this->assertSame('manual', ForwardingMarker::type($wire));
            $this->assertStringEndsWith("\r\n", $wire);
            $this->assertStringNotContainsString('emails/', $wire);
            $parsed = (new MailMimeParser)->parse($wire, false);
            $this->assertSame('shop@sender.test', $parsed->getHeader('From')->getEmail());
            $this->assertSame('shop@sender.test', $parsed->getHeader('Reply-To')?->getEmail() ?? $parsed->getHeader('From')->getEmail());
            $this->assertDoesNotMatchRegularExpression('/^(?:DKIM-Signature|ARC-Message-Signature):/im', $wire);
            $this->assertStringContainsString("Authentication-Results: mx.mailmm.test; none\r\n", $wire);
            $this->assertSame('reader@outside.test', $parsed->getHeader('To')->getEmail());
            $this->assertSame('Receipt', $parsed->getSubject());
            $this->assertStringContainsString('shop@sender.test', $parsed->getTextContent());
            $this->assertStringContainsString('Сохранённый текст', $parsed->getTextContent());
            $this->assertStringContainsString('<p><b>Сохранённая вёрстка</b></p>', $parsed->getHtmlContent());
            $this->assertCount(1, $parsed->getAllAttachmentParts());
            $file = $parsed->getAttachmentPart(0);
            $this->assertSame('чек.pdf', $file->getFilename());
            $this->assertSame('application/pdf', $file->getContentType());
            $this->assertSame($bytes, (string) $file->getBinaryContentStream());
        });
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame('accepted', $op->fresh()->status);
    }

    public function test_manual_can_send_attachment_only_record_after_original_file_was_lost(): void
    {
        Storage::disk('local')->delete('test.eml');
        $key = str_repeat('f', 64);
        $path = "mail/ff/{$key}-0";
        $this->message->update(['delivery_key' => $key, 'attachments' => [['path' => $path, 'filename' => 'empty.bin',
            'mime' => "text/plain\r\nBcc: leak@other.test", 'size' => 0]]]);
        Storage::disk('local')->put($path, '');
        $op = $this->operation();
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andReturnUsing(function ($mode, $message) {
            $wire = $message->toString();
            $this->assertStringNotContainsString('leak@other.test', $wire);
            $file = (new MailMimeParser)->parse($wire, false)->getAttachmentPart(0);
            $this->assertSame('empty.bin', $file->getFilename());
            $this->assertSame('application/octet-stream', $file->getContentType());
            $this->assertSame('', (string) $file->getBinaryContentStream());
        });
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame('accepted', $op->fresh()->status);
    }

    public function test_empty_archived_letter_can_be_manually_forwarded_and_keeps_its_subject(): void
    {
        $this->message->update(['raw_path' => null, 'raw_size' => null, 'raw_sha256' => null]);
        $op = $this->operation();
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andReturnUsing(function ($mode, $message) {
            $wire = $message->toString();
            $this->assertStringEndsWith("\r\n", $wire);
            $this->assertSame('manual', ForwardingMarker::type($wire));
            $this->assertSame('Receipt', (new MailMimeParser)->parse($wire, false)->getSubject());
        });
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame('accepted', $op->fresh()->status);
    }

    public static function unusableArchivedSenders(): array
    {
        return ['missing' => [''], 'malformed' => ["shop@sender.test\r\nBcc: blind@private.test"]];
    }

    #[DataProvider('unusableArchivedSenders')]
    public function test_archived_letter_without_a_usable_sender_can_be_forwarded_with_an_explicit_unknown_author(string $archivedFrom): void
    {
        $this->message->update(['raw_path' => null, 'raw_size' => null, 'raw_sha256' => null, 'from' => $archivedFrom]);
        $mail = (new MailMimeParser)->parse($this->manualWire(), false);
        $this->assertNull($mail->getHeader('Bcc'));
        $from = $mail->getHeader('From');
        $this->assertSame('service@mailmm.test', $from->getEmail());
        $this->assertSame('Исходный отправитель неизвестен', $from->getPersonName());
    }

    public function test_manual_forward_with_no_ingress_context_strips_spoofed_authentication(): void
    {
        $raw = "Authentication-Results: mx.mailmm.test; dkim=pass header.d=sender.test\r\n".self::RAW;
        Storage::disk('local')->put('test.eml', $raw);
        $this->message->update(['raw_sha256' => hash('sha256', $raw), 'raw_size' => strlen($raw), 'forwarding_context' => null]);
        $wire = $this->manualWire();
        preg_match_all('/^Authentication-Results: ([^\r\n]*)/im', $wire, $matches);
        $this->assertSame(['mx.mailmm.test; none'], $matches[1]);
        $this->assertStringNotContainsString('dkim=pass', $wire);
    }

    public static function invalidRawAuthors(): array
    {
        return [
            'missing' => [''],
            'empty' => ["From:\r\n"],
            'ambiguous' => ["From: shop@sender.test\r\nFrom: other@sender.test\r\n"],
            'malformed' => ["From: no mailbox\r\n"],
        ];
    }

    #[DataProvider('invalidRawAuthors')]
    public function test_manual_preserves_original_content_when_the_sender_header_is_unusable(string $authorHeaders): void
    {
        $raw = $authorHeaders."Reply-To: unsafe@other.test\r\nDKIM-Signature: unverified\r\n"
            ."ARC-Authentication-Results: i=1; sender.test; dmarc=pass\r\nSubject: Receipt\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=receipt\r\n\r\n"
            ."--receipt\r\nContent-Type: text/html\r\n\r\n<b>Original HTML</b>\r\n"
            ."--receipt\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=receipt.bin\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\nAP8=\r\n--receipt--\r\n";
        Storage::disk('local')->put('test.eml', $raw);
        $this->message->update(['raw_sha256' => hash('sha256', $raw), 'raw_size' => strlen($raw)]);
        $wire = $this->manualWire();
        $mail = (new MailMimeParser)->parse($wire, false);
        $this->assertSame('service@mailmm.test', $mail->getHeader('From')->getEmail());
        $this->assertSame('Исходный отправитель неизвестен', $mail->getHeader('From')->getPersonName());
        $this->assertNull($mail->getHeader('Reply-To'));
        $this->assertNull($mail->getHeader('DKIM-Signature'));
        $this->assertNull($mail->getHeader('ARC-Authentication-Results'));
        preg_match_all('/^Authentication-Results: ([^\r\n]*)/im', $wire, $results);
        $this->assertSame(['mx.mailmm.test; none'], $results[1]);
        $this->assertSame('<b>Original HTML</b>', $mail->getHtmlContent());
        $file = $mail->getAttachmentPart(0);
        $this->assertSame('receipt.bin', $file->getFilename());
        $this->assertSame("\x00\xff", (string) $file->getBinaryContentStream());
    }

    public static function unusableAttachments(): array
    {
        return [['missing'], ['wrong_size'], ['cross_message'], ['traversal'], ['oversized']];
    }

    #[DataProvider('unusableAttachments')]
    public function test_legacy_delivery_rejects_incomplete_or_misbound_attachments_before_smtp(string $problem): void
    {
        $directory = "emails/{$this->message->id}/";
        $path = match ($problem) {
            'cross_message' => 'emails/999/private.bin',
            'traversal' => $directory.'../999/private.bin',
            default => $directory.'file.bin',
        };
        if ($problem === 'oversized') {
            config(['forwarding.max_bytes' => 16]);
        }
        if ($problem !== 'missing') {
            Storage::disk('local')->put($path, 'private');
        }
        $this->message->update(['raw_path' => null, 'raw_size' => null, 'raw_sha256' => null,
            'attachments' => [['path' => $path, 'filename' => 'file.bin', 'mime' => 'application/octet-stream',
                'size' => match ($problem) {
                    'wrong_size' => 4, 'oversized' => 64, default => 7
                }]]]);
        $op = $this->operation();
        $this->mock(ForwardingTransport::class)->shouldNotReceive('send');
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame($problem === 'oversized' ? 'message_too_large' : 'archive_attachment_unavailable', $op->fresh()->error_code);
    }

    public function test_worker_rechecks_active_version_after_scan_and_immediately_before_submission(): void
    {
        $op = $this->operation('auto');
        $this->mock(ForwardingIngress::class)->shouldReceive('authenticationResults')->once()->andReturnUsing(function () use ($op) {
            MailboxForwardingRule::whereKey($op->rule_id)->update(['version' => 2]);

            return 'mx.mailmm.test; dkim=pass';
        });
        $this->mock(ForwardingTransport::class)->shouldNotReceive('send');
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame('cancelled', $op->fresh()->status);
        $this->assertSame('rule_changed', $op->fresh()->error_code);
    }

    public function test_certain_deferral_queues_once_and_ambiguous_result_never_queues_again(): void
    {
        $op = $this->operation();
        $relay = $this->mock(ForwardingTransport::class);
        $relay->shouldReceive('send')->once()->andThrow(new ForwardingFailure('smtp_temporary', 'retry'));
        $relay->shouldReceive('send')->once()->andThrow(new ForwardingFailure('submission_unknown', 'unknown'));
        $worker = app(ForwardingDelivery::class);
        $worker->send($op->id);
        $this->assertSame('queued', $op->refresh()->status);
        Queue::assertPushed(SendMailForward::class, 1);
        $worker->send($op->id);
        $this->travelTo($op->next_attempt_at->addSecond());
        $worker->send($op->id);
        $worker->send($op->id);
        $this->assertSame('unknown', $op->fresh()->status);
        Queue::assertPushed(SendMailForward::class, 1);
    }

    public function test_scheduler_only_reissues_abandoned_preparation(): void
    {
        $safe = $this->operation();
        $ambiguous = $this->operation();
        foreach ([[$safe, 'preparing'], [$ambiguous, 'submitting']] as [$op, $status]) {
            DB::table('mail_forwards')->where('id', $op->id)->update(['status' => $status, 'attempt_id' => Str::uuid()->toString(),
                'updated_at' => now()->subMinutes(5)]);
        }
        $this->artisan('mail:recover-forwards')->assertSuccessful();
        $this->artisan('mail:recover-forwards')->assertSuccessful();
        $this->assertSame('queued', $safe->fresh()->status);
        $this->assertSame('unknown', $ambiguous->fresh()->status);
        Queue::assertPushed(SendMailForward::class, 1);
    }

    public static function revokedSubmissions(): array
    {
        return [
            ['password_reset', 'defer', 'failed', 'password_reset'],
            ['mailbox_closed', 'defer', 'failed', 'mailbox_closed'],
            ['password_reset', 'accept', 'accepted', null],
            ['mailbox_closed', 'disconnect', 'unknown', 'submission_unknown'],
        ];
    }

    private function recordedAttemptWarnings(): Collection
    {
        config(['logging.default' => 'forward-test', 'logging.channels.forward-test' => [
            'driver' => 'single', 'path' => Storage::disk('local')->path('delivery.log'), 'level' => 'warning',
        ]]);
        $logs = collect();
        Log::listen(function (MessageLogged $event) use ($logs) {
            if ($event->message === 'Mail forwarding attempt completed') {
                $logs->push($event);
            }
        });

        return $logs;
    }

    public function test_terminal_failures_before_smtp_and_during_recovery_warn_once(): void
    {
        $missing = $this->operation('auto');
        $ambiguous = $this->operation();
        $expired = $this->operation();
        $expired->forceFill(['created_at' => now()->subHours(25)])->save();
        $ambiguous->update(['status' => 'submitting', 'attempt_id' => (string) Str::uuid(), 'updated_at' => now()->subMinutes(5)]);
        Storage::disk('local')->delete('test.eml');
        $this->mock(ForwardingTransport::class)->shouldNotReceive('send');
        $logs = $this->recordedAttemptWarnings();
        for ($i = 0; $i < 2; $i++) {
            app(ForwardingDelivery::class)->send($missing->id);
            $this->artisan('mail:recover-forwards')->assertSuccessful();
        }
        $this->assertCount(3, $logs);
        $this->assertSame(['original_unavailable', 'delivery_expired', 'submission_unknown'], $logs->pluck('context.reason')->all());
        $this->assertSame([$missing->id, $expired->id, $ambiguous->id], $logs->pluck('context.id')->all());
    }

    #[DataProvider('revokedSubmissions')]
    public function test_revocation_allows_the_current_smtp_result_but_stops_a_new_attempt(string $reason, string $relayReply, string $status, ?string $error): void
    {
        $op = $this->operation();
        $box = $this->owner->mailbox;
        $logs = $this->recordedAttemptWarnings();
        $server = new Process([PHP_BINARY, base_path('tests/fixtures/forwarding-smtp.php'), $relayReply]);
        $server->setTimeout(15)->start();
        try {
            $server->waitUntil(fn () => str_contains($server->getOutput(), "\n"));
            config(['forwarding.auto_dsn' => 'smtp://'.trim($server->getOutput()), 'forwarding.timeout' => 2]);
            $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andReturnUsing(function (...$args) use ($op, $box, $reason) {
                $this->assertSame('submitting', $op->fresh()->status);
                if ($reason === 'password_reset') {
                    $admin = User::factory()->create(['is_admin' => true]);
                    $this->actingAs($admin)->postJson("/admin/mailboxes/{$box->id}/password", ['return_credentials' => true])->assertOk();
                } else {
                    $box->update(['closed_at' => now()]);
                }
                (new ForwardingTransport)->send(...$args);
            });
            app(ForwardingDelivery::class)->send($op->id);
            $server->wait();
            $this->assertTrue($server->isSuccessful(), $server->getErrorOutput());
        } finally {
            $server->stop();
        }
        $op->refresh();
        $this->assertSame($status, $op->status);
        $this->assertSame($error, $op->error_code);
        $this->assertNull($op->next_attempt_at);
        Queue::assertNotPushed(SendMailForward::class);
        if ($status === 'accepted') {
            $this->assertCount(0, $logs);

            return;
        }
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]->level);
        $this->assertIsInt($logs[0]->context['age_seconds']);
        $this->assertSame(['id' => $op->id, 'status' => $status, 'attempts' => 1, 'reason' => $error,
            'age_seconds' => $logs[0]->context['age_seconds']], $logs[0]->context);
    }

    public static function deliveryReports(): array
    {
        return [['5.1.1', 'permanent_bounce'], ['5.2.1', 'permanent_bounce'], ['5.6.0', 'smtp_rejected'],
            ['5.1.1', 'smtp_rejected', 'expanded@other.test'],
            ['5.7.1', 'smtp_rejected'], ['5.0.0', 'smtp_rejected'], ['5.2.2', 'smtp_rejected']];
    }

    #[DataProvider('deliveryReports')]
    public function test_dsn_requires_signed_recipient_matching_delivery_status_and_repeats_once(string $status, string $reason, string $final = 'reader@outside.test'): void
    {
        $op = $this->operation('auto');
        $op->update(['status' => 'accepted']);
        $to = ForwardingFeedback::address($op->id);
        $dsn = "Content-Type: multipart/report; report-type=delivery-status; boundary=q\r\n\r\n--q\r\nContent-Type: text/plain\r\n\r\nFailed\r\n"
            ."--q\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example\r\n\r\n"
            ."Original-Recipient: rfc822; reader@outside.test\r\nFinal-Recipient: rfc822; ".$final."\r\nAction: failed\r\nStatus: ".$status."\r\n\r\n--q--\r\n";
        $badToken = substr_replace($to, str_contains('123456789abcdef', $to[33]) ? '0' : '1', 33, 1);
        $this->assertTrue(ForwardingFeedback::receiveDsn($badToken, $dsn));
        $this->assertSame('accepted', $op->fresh()->status);
        ForwardingFeedback::receiveDsn($to, str_replace('reader@outside.test', 'other@outside.test', $dsn));
        $this->assertSame('accepted', $op->fresh()->status);
        ForwardingFeedback::receiveDsn($to, $dsn);
        ForwardingFeedback::receiveDsn($to, $dsn);
        $this->assertSame('bounced', $op->fresh()->status);
        $this->assertSame($reason, $op->fresh()->error_code);
        $rule = MailboxForwardingRule::find($op->rule_id);
        if ($reason === 'permanent_bounce') {
            $this->assertSame(2, $rule->version);
            $this->assertSame('disabled', $rule->status);
        } else {
            $this->assertSame(1, $rule->version);
            $this->assertSame('active', $rule->status);
            $this->assertSame($reason, $rule->last_error_code);
            ForwardingFeedback::record($op->id, 'complaint');
            $this->assertSame('disabled', $rule->fresh()->status);
        }
    }

    public static function relayFailures(): array
    {
        return [['smtp_rejected', 'active'], ['recipient_rejected', 'disabled']];
    }

    #[DataProvider('relayFailures')]
    public function test_worker_records_permanent_smtp_failure_and_only_disables_an_unreachable_destination(string $reason, string $ruleStatus): void
    {
        $op = $this->operation('auto');
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->once()->andThrow(new ForwardingFailure($reason));
        app(ForwardingDelivery::class)->send($op->id);
        $this->assertSame('failed', $op->fresh()->status);
        $this->assertSame($reason, $op->fresh()->error_code);
        $this->assertSame($ruleStatus, MailboxForwardingRule::find($op->rule_id)->status);
    }

    public function test_webhook_requires_signature_and_does_not_disable_a_later_rule_version(): void
    {
        $op = $this->operation('auto');
        $op->update(['status' => 'unknown']);
        MailboxForwardingRule::whereKey($op->rule_id)->update(['version' => 2]);
        config(['forwarding.webhook_secret' => 'test-secret']);
        $body = json_encode(['operation_id' => $op->id, 'event' => 'complaint']);
        $request = fn (array $headers) => $this->call('POST', '/api/forwarding/feedback', [], [], [], $headers, $body);
        $request([])->assertForbidden();
        $time = (string) now()->timestamp;
        $request(['HTTP_X_MAILMM_TIMESTAMP' => $time, 'HTTP_X_MAILMM_SIGNATURE' => hash_hmac('sha256', $time.'.'.$body, 'test-secret')])->assertNoContent();
        $this->assertSame('complaint', $op->fresh()->error_code);
        $this->assertSame('active', MailboxForwardingRule::find($op->rule_id)->status);
    }
}
