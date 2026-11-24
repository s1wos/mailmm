<?php

namespace Tests\Feature;

use App\DTO\PostfixReceipt;
use App\Exceptions\ForwardingCheckFailed;
use App\Models\MailboxForwardingRule;
use App\Models\MailForward;
use App\Models\Message;
use App\Models\User;
use App\Services\ForwardingIngress;
use App\Services\ForwardingMarker;
use App\Services\MailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ForwardingIngressTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'mx.mailmm.test';

    private const CONTEXT = ['sender' => 'bounce@shop.test', 'helo' => 'mx.shop.test', 'ip' => '192.0.2.7'];

    protected function setUp(): void
    {
        parent::setUp();
        ParallelTesting::resolveTokenUsing(fn () => 'ingress_'.getmypid());
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['forwarding.authserv_id' => self::HOST, 'forwarding.scanner_url' => 'http://scanner.test/checkv2',
            'forwarding.auto_enabled' => true, 'forwarding.auto_enabled_at' => now()->subDay()->toISOString()]);
    }

    private function ingest(?array $context, string $headers = '', string $body = "Receipt\r\n", bool $withRule = true): Message
    {
        $owner = User::factory()->create();
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        if ($withRule) {
            MailboxForwardingRule::create(['email_id' => $box->id, 'status' => 'active', 'version' => 1,
                'destination' => 'client@example.org', 'activated_at' => now()]);
        }
        $this->travel(2)->seconds();
        $raw = 'Received: by '.self::HOST.' (Postfix) id ABCDEF; '.now()->format('D, j M Y H:i:s O')."\r\n"
            .$headers."From: Shop <author@shop.test>\r\nTo: original@shop.test\r\nSubject: Receipt\r\n\r\n".$body;
        $receipt = PostfixReceipt::fromMessage($raw, self::HOST);
        $service = app(MailDeliveryService::class);
        $service->deliver($box->email, $raw, $receipt, $context);
        $service->deliver($box->email, $raw, $receipt, $context);

        return Message::where('email_id', $box->id)->sole();
    }

    public function test_scanner_uses_smtp_envelope_and_records_its_own_results_on_the_published_original(): void
    {
        Http::fake(['scanner.test/*' => Http::response(['action' => 'no action',
            'milter' => ['add_headers' => ['Authentication-Results' => ['value' => self::HOST.";\n\tnone"]]]])]);
        $mail = $this->ingest(self::CONTEXT, "Authentication-Results: prior.test; spf=pass smtp.helo=mx.mailmm.test\r\n");
        $this->assertFalse($mail->is_pending);
        $this->assertSame(self::HOST.'; none', $mail->forwarding_context['authentication_results']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('IP', self::CONTEXT['ip'])
            && $request->hasHeader('From', self::CONTEXT['sender']) && $request->hasHeader('Helo', self::CONTEXT['helo'])
            && $request->hasHeader('Rcpt', $mail->recipient) && $request->hasHeader('MTA-Name', self::HOST)
            && $request->body() === Storage::disk('local')->get($mail->raw_path));
        $this->assertSame('queued', MailForward::sole()->status);
        $this->assertDatabaseCount('mail_forwards', 1);
    }

    public static function manualScreeningResults(): array
    {
        return [
            'real authentication' => [
                ['action' => 'no action', 'milter' => ['add_headers' => ['Authentication-Results' => ['value' => self::HOST.'; dkim=pass header.d=shop.test']]]],
                ['authentication_results' => self::HOST.'; dkim=pass header.d=shop.test'],
            ],
            'temporary scanner outage' => [['action' => 'soft reject'], ['manual_check_deferred' => true]],
            'infected attachment' => [['action' => 'reject', 'symbols' => ['CLAM_VIRUS' => []]], ['blocked_reason' => 'blocked_by_filter']],
        ];
    }

    #[DataProvider('manualScreeningResults')]
    public function test_manual_records_ingress_results_without_an_automatic_forwarding_rule(array $scannerReply, array $outcome): void
    {
        config(['forwarding.manual_enabled' => true]);
        Http::fake(['scanner.test/*' => Http::response($scannerReply)]);
        $mail = $this->ingest(self::CONTEXT, "Authentication-Results: prior.test; dmarc=pass\r\n", withRule: false);
        $this->assertTrue($mail->hasOriginal());
        $this->assertSame(self::CONTEXT + $outcome, $mail->forwarding_context);
        $this->assertDatabaseCount('mail_forwards', 0);
        Http::assertSentCount(1);
    }

    public static function localAuthorities(): array
    {
        return [["(previous hop)\r\n mx.mailmm.test"], ['"mx.mailmm.test" 1'], ['"mx\\.mailmm.test"']];
    }

    #[DataProvider('localAuthorities')]
    public function test_spoofed_local_authentication_is_archived_and_skipped_before_a_scan(string $authority): void
    {
        Http::fake();
        $mail = $this->ingest(self::CONTEXT, 'Authentication-Results: '.$authority."; dmarc=pass\r\n");
        $this->assertTrue($mail->hasOriginal());
        $this->assertSame('untrusted_authentication_results', MailForward::sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_only_feedback_and_returned_automatic_mail_end_the_chain(): void
    {
        Http::fake();
        $this->ingest(ForwardingIngress::context('<>', '192.0.2.5', 'mx.shop.test'));
        $this->ingest(self::CONTEXT, "Content-Type: multipart/report;\r\n report-type=delivery-status; boundary=a\r\n");
        $id = '11003344-0000-4011-a222-222222222222';
        foreach (['verification', 'auto'] as $type) {
            $marker = ForwardingMarker::value($type, $id, "Receipt\r\n");
            $this->ingest(self::CONTEXT, ForwardingMarker::HEADER.': '.$marker."\r\n");
        }
        $this->assertDatabaseCount('mail_forwards', 0);
        $this->assertDatabaseCount('messages', 4);
        Http::assertNothingSent();
        $manual = ForwardingMarker::value('manual', $id, "Receipt\r\n");
        $this->assertFalse(ForwardingIngress::suppressAutomatic(ForwardingMarker::HEADER.': '.$manual."\r\n\r\nReceipt\r\n"));
    }

    public static function unusableResults(): array
    {
        return [
            [[], 'scanner_unavailable', true],
            [['action' => 'reject'], 'blocked_by_filter', false],
            [['action' => 'soft reject'], 'scanner_deferred', true],
            [['action' => 'add header', 'milter' => ['add_headers' => ['Authentication-Results' => ['value' => self::HOST.'; spf=temperror']]]], 'scanner_deferred', true],
            [['symbols' => ['CLAM_VIRUS_FAIL' => []]], 'scanner_unavailable', true],
            [['symbols' => ['CLAM_VIRUS' => []]], 'blocked_by_filter', false],
            [['milter' => ['add_headers' => ['Authentication-Results' => ['value' => "mx.mailmm.test; none\r\nBcc: forward@test"]]]], 'scanner_unavailable', true],
        ];
    }

    #[DataProvider('unusableResults')]
    public function test_filter_failures_never_publish_untrusted_results(array $overrides, string $reason, bool $temporary): void
    {
        $defaults = ['action' => 'no action'];
        Http::fake(['scanner.test/*' => Http::response($overrides + $defaults)]);
        $message = new Message(['recipient' => 'box@mailmm.test', 'forwarding_context' => self::CONTEXT]);
        try {
            app(ForwardingIngress::class)->authenticationResults($message, "From: test@shop.test\r\n\r\nHi\r\n");
            self::fail('Expected a filter failure');
        } catch (ForwardingCheckFailed $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($temporary, $e->temporary);
        }
    }

    public function test_unavailable_filter_leaves_mail_readable_and_defers_automatic_verification(): void
    {
        config(['forwarding.scanner_url' => '']);
        $mail = $this->ingest(self::CONTEXT);
        $this->assertTrue($mail->hasOriginal());
        $this->assertSame(self::CONTEXT, $mail->forwarding_context);
        $this->assertSame('queued', MailForward::sole()->status);
    }
}
