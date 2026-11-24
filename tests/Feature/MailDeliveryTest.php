<?php

namespace Tests\Feature;

use App\Events\NewMessageReceived;
use App\Models\Email;
use App\Models\MailboxDomain;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const RAW = "Received: by mx.example.test id ABCDEF\r\n"
        ."From: Sender <sender@example.org>\r\nTo: other@mail.test\r\n"
        ."Date: Wed, 1 Jan 1997 00:00:00 +0000\r\nMessage-ID: <reused@example.org>\r\n"
        ."Subject: =?utf-8?B?0J/RgNC40LLQtdGC?=\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/mixed; boundary=outer\r\n\r\n"
        ."--outer\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
        ."<html><head><style>body{color:red}</style></head><body><p>Hello &amp; welcome!</p><p>Second line.</p></body></html>\r\n"
        ."--outer\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=sample.bin\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\nAP8=\r\n"
        ."--outer\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=sample.bin\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\nYg==\r\n--outer--\r\n";

    private const TRACE = "Received: from sender.example.org (sender.example.org [192.0.2.12])\r\n"
        ."\tby mx.mail.test (Postfix) with ESMTP id 4321ABC123\r\n"
        ."\tfor <guest@mail.test>; Sun, 8 Nov 2026 23:04:05 +0500 (+05)\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        MailboxDomain::updateOrCreate(['name' => 'mail.test'], ['issuance_enabled' => true]);
        config(['mailbox.mx_host' => 'mx.mail.test', 'mailbox.max_message_bytes' => 10 * 1024 * 1024]);
        ParallelTesting::resolveTokenUsing(fn () => 'mail_'.getmypid());
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(now()->startOfSecond());
    }

    private function deliver(string $recipient, string $raw = self::RAW, bool $postfix = false, ?int &$bytesRead = null): int
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $raw);
        rewind($stream);
        $arguments = ['command' => 'mail:parse', '--recipient' => $recipient];
        if ($postfix) {
            $arguments['--postfix'] = true;
        }
        $input = new ArrayInput($arguments);
        $input->setStream($stream);
        try {
            return app(Kernel::class)->handle($input, new BufferedOutput);
        } finally {
            $bytesRead = ftell($stream);
            fclose($stream);
        }
    }

    public function test_queued_mail_is_assigned_only_to_an_address_already_open_when_postfix_accepted_it(): void
    {
        Event::fake([NewMessageReceived::class]);
        $this->travelTo('2026-11-08 18:04:04 UTC');
        $old = Email::create(['email' => 'old@mail.test', 'expires_at' => now()->addSeconds(3)]);
        $closed = Email::create(['email' => 'closed@mail.test', 'closed_at' => now()]);
        $this->travel(1)->second();
        $ambiguous = Email::create(['email' => 'same-second@mail.test']);
        $this->travel(1)->day();
        $new = Email::create(['email' => 'new@mail.test']);
        $raw = self::TRACE.self::RAW;
        foreach ([$old, $closed, $ambiguous, $new] as $mailbox) {
            self::assertSame(0, $this->deliver($mailbox->email, $raw, postfix: true));
        }
        $letter = Message::where('email_id', $old->id)->sole();
        self::assertSame('2026-11-08 18:04:05', $letter->received_at->utc()->toDateTimeString());
        self::assertEqualsCanonicalizing([$new->email, $closed->email, $ambiguous->email], Message::whereNull('email_id')->pluck('recipient')->all());
        Event::assertNotDispatched(NewMessageReceived::class);

        self::assertSame(0, $this->deliver($new->email, self::TRACE."From: sender@example.org\n\nDirect"));
        self::assertTrue(now()->equalTo(Message::where('email_id', $new->id)->sole()->received_at));
    }

    public function test_archive_retains_the_complete_original_and_removes_it_at_expiry(): void
    {
        $raw = self::TRACE.self::RAW;
        self::assertSame(0, $this->deliver('archive@mail.test', str_replace("\r\n", "\n", $raw), postfix: true));
        $message = Message::sole();
        self::assertTrue($message->hasOriginal());
        self::assertSame($raw, Storage::disk('local')->get($message->raw_path));
        self::assertSame(hash('sha256', $raw), $message->raw_sha256);
        self::assertSame(strlen($raw), $message->raw_size);
        self::assertFalse((new Message)->hasOriginal());
        $this->travelTo(Message::expiresAt($message->received_at));
        $this->artisan('mail:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($message->raw_path);
    }

    public function test_bcc_and_retried_queue_deliveries_stay_with_the_full_address_after_issuance_stops(): void
    {
        Event::fake([NewMessageReceived::class]);
        $this->travelTo('2026-11-08 18:03:00 UTC');
        $secondDomain = MailboxDomain::create(['name' => 'second.test', 'issuance_enabled' => true]);
        $first = Email::create(['email' => 'guest@mail.test']);
        $second = Email::create(['email' => 'guest@second.test']);
        $this->travel(2)->minutes();
        $secondDomain->update(['issuance_enabled' => false]);

        foreach ([$first, $second, $second] as $box) {
            self::assertSame(0, $this->deliver($box->email, self::TRACE.self::RAW, postfix: true));
            self::assertEquals($box->id, Message::where('recipient', $box->email)->sole()->email_id);
        }
        self::assertSame(2, Message::count());
        self::assertSame(67, $this->deliver('guest@unknown.second.test'));
    }

    public function test_unavailable_domain_catalog_defers_the_envelope_decision(): void
    {
        $connection = config('database.default');
        config(['database.default' => 'unavailable']);
        try {
            self::assertSame(75, $this->deliver('guest@new.test'));
        } finally {
            config(['database.default' => $connection]);
        }
    }

    public function test_postfix_mode_defers_on_untrusted_or_invalid_first_trace(): void
    {
        $this->travelTo('2026-11-09 00:00:00 UTC');
        foreach ([
            self::RAW,
            str_replace('mx.mail.test', 'foreign.example.org', self::TRACE).self::TRACE.self::RAW,
            "From: spoof@example.org\r\n".self::TRACE.self::RAW,
            str_replace('8 Nov', '38 Nov', self::TRACE).self::RAW,
            str_replace('2026', '2027', self::TRACE).self::RAW,
            str_replace('4321ABC123', '3Pt2mN2VXxznjll', self::TRACE).self::RAW,
        ] as $raw) {
            self::assertSame(75, $this->deliver('guest@mail.test', $raw, postfix: true));
        }
        self::assertSame(0, Message::count());
    }

    public function test_a_renamed_mx_can_drain_its_own_preserved_spool(): void
    {
        $this->travelTo('2026-11-09 00:00:00 UTC');
        config(['mailbox.mx_host' => 'replacement.mail.test', 'mailbox.previous_mx_hosts' => ['mx.mail.test']]);
        self::assertSame(0, $this->deliver('guest@mail.test', self::TRACE.self::RAW, postfix: true));
        self::assertSame('2026-11-08 18:04:05', Message::sole()->received_at->utc()->toDateTimeString());
        $otherTrace = str_replace('mx.mail.test', 'unrelated.example.test', self::TRACE);
        self::assertSame(75, $this->deliver('guest@mail.test', $otherTrace.self::TRACE.self::RAW, postfix: true));
        self::assertSame(1, Message::count());
    }

    public function test_a_lower_intake_limit_still_delivers_mail_from_the_preserved_spool(): void
    {
        $this->travelTo('2026-11-09 00:00:00 UTC');
        $raw = self::TRACE.self::RAW;
        config(['mailbox.max_message_bytes' => strlen($raw) - 100]);

        self::assertSame(65, $this->deliver('archive@mail.test', $raw));
        self::assertSame(0, $this->deliver('archive@mail.test', $raw, postfix: true));
        $delivered = Message::query()->retained()->sole();
        self::assertSame('2026-11-08 18:04:05', $delivered->received_at->utc()->toDateTimeString());
        self::assertSame('b', Storage::disk('local')->get($delivered->attachments[1]['path']));
    }

    public function test_an_invalid_new_intake_limit_preserves_queued_delivery(): void
    {
        $this->travelTo('2026-11-09 00:00:00 UTC');
        config(['mailbox.max_message_bytes' => '0']);

        self::assertSame(75, $this->deliver('archive@mail.test'));
        self::assertSame(0, $this->deliver('archive@mail.test', self::TRACE.self::RAW, postfix: true));
        self::assertSame('b', Storage::disk('local')->get(Message::sole()->attachments[1]['path']));
    }

    public function test_intake_preflight_validates_and_prints_the_postfix_limit(): void
    {
        foreach (['0', '-1', '10bytes', '', (string) PHP_INT_MAX] as $invalid) {
            config(['mailbox.max_message_bytes' => $invalid]);
            $this->artisan('mail:parse --check-limit')->assertFailed();
            self::assertSame(75, $this->deliver('archive@mail.test'));
        }
        config(['mailbox.max_message_bytes' => '001024']);
        $this->artisan('mail:parse --check-limit')->expectsOutput('1024')->assertSuccessful();
        $this->artisan('mail:parse --check-limit --postfix')->expectsOutput('1024')->assertSuccessful();
    }

    public function test_postfix_long_queue_id_distinguishes_mailbox_creation_within_the_same_second(): void
    {
        Event::fake([NewMessageReceived::class]);
        $raw = str_replace(
            ['4321ABC123', 'Sun, 8 Nov 2026 23:04:05 +0500 (+05)'],
            ['3Pt2mN2VXxznjll', 'Sun, 20 Mar 2011 14:31:47 +0000 (UTC)'],
            self::TRACE
        ).self::RAW;
        $this->travelTo('2011-03-20 14:31:48.200000 UTC');
        $early = Email::create(['email' => 'early@mail.test']);
        $this->travelTo('2011-03-20 14:31:48.400000 UTC');
        $late = Email::create(['email' => 'late@mail.test']);
        self::assertSame(0, $this->deliver($early->email, $raw, postfix: true));
        self::assertSame(0, $this->deliver($late->email, $raw, postfix: true));
        $message = Message::where('email_id', $early->id)->sole();
        self::assertSame('2011-03-20 14:31:48.353025', $message->received_at->format('Y-m-d H:i:s.u'));
        self::assertSame($late->email, Message::whereNull('email_id')->sole()->recipient);
    }

    public function test_delayed_postfix_mail_expires_365_days_after_smtp_acceptance(): void
    {
        $this->travelTo('2027-11-08 18:04:05 UTC');
        self::assertSame(0, $this->deliver('archive@mail.test', self::TRACE.self::RAW, postfix: true));
        self::assertSame(0, Message::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pruning_keeps_the_ownership_of_queued_mail_after_expiration_or_closure(): void
    {
        $this->travelTo('2026-11-08 18:04:00 UTC');
        $expired = Email::create(['email' => 'expired@mail.test', 'expires_at' => now()->addMinute()]);
        $closed = Email::create(['email' => 'closed@mail.test', 'expires_at' => now()->addDay()]);
        $this->travel(1)->minute();
        $closed->update(['closed_at' => now()]);
        $this->travel(364)->days();
        $this->artisan('mail:prune')->assertSuccessful();
        foreach ([$expired, $closed] as $mailbox) {
            self::assertSame(0, $this->deliver($mailbox->email, self::TRACE.self::RAW, postfix: true));
            self::assertEquals($mailbox->id, Message::where('recipient', $mailbox->email)->sole()->email_id);
        }
        $this->travel(1)->day();
        $this->artisan('mail:prune')->assertSuccessful();
        self::assertSame(0, Message::count());
        self::assertSame(0, Email::count());
    }

    public function test_legacy_html_only_mail_is_readable_in_the_api_and_panel(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create();
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        $html = '<style>private-style</style><p>Receipt &amp; details</p><p>Open <a href="https://example.test/order">order</a></p>';
        $message = $box->messages()->create(['recipient' => $box->email, 'from' => 'sender@example.test',
            'received_at' => now(), 'body_html' => $html]);
        $text = "Receipt & details\nOpen order (https://example.test/order)";
        $this->actingAs($owner);
        foreach ([null, '', 'Already plain'] as $storedText) {
            $this->travel(1)->second();
            $message->update(['body_text' => $storedText]);
            $expected = $storedText ?: $text;
            $this->getJson('/api/messages/'.$message->id)->assertOk()->assertJsonPath('data.body_text', $expected)
                ->assertJsonPath('data.html_preview', fn ($preview) => is_string($preview) && str_contains($preview, 'href="https://example.test/order"'))
                ->assertJsonMissingPath('data.body_html');
            $this->getJson('/admin/messages/'.$message->id)->assertOk()->assertJsonPath('letter.body_text', $expected)
                ->assertJsonPath('letter.html_preview', fn ($preview) => is_string($preview) && str_contains($preview, 'href="https://example.test/order"'))
                ->assertJsonMissingPath('letter.body_html');
        }
    }

    public function test_both_readers_show_html_from_the_verified_original_and_fall_back_to_stored_text(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create(['email' => 'reader@mail.test']);
        $owner->mailbox()->create(['email' => $owner->email]);
        $raw = "From: shop@example.org\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=alt\r\n\r\n"
            ."--alt\r\nContent-Type: text/plain\r\n\r\nOrder summary\r\n"
            ."--alt\r\nContent-Type: text/html\r\n\r\n<p><a href=\"https://example.test/order\">View order</a></p>\r\n--alt--\r\n";
        self::assertSame(0, $this->deliver($owner->email, $raw));
        $letter = Message::sole();
        $path = $letter->raw_path;
        self::assertNull($letter->body_html);
        $disk = Storage::disk('local');
        $this->actingAs($owner);
        $this->getJson('/api/messages')->assertOk()->assertJsonMissingPath('data.0.html_preview');
        foreach (['api' => 'data', 'admin' => 'letter'] as $scope => $key) {
            $preview = $this->getJson("/$scope/messages/$letter->id")->assertOk()
                ->assertJsonMissingPath("$key.body_html")->assertJsonPath("$key.body_text", 'Order summary')->json("$key.html_preview");
            self::assertIsString($preview);
            self::assertStringContainsString('href="https://example.test/order"', $preview);
            self::assertStringNotContainsString('Order summary', $preview);
        }
        self::assertSame($raw, $disk->get($path));
        $disk->put($path, str_replace('example.test/order', 'example.test/other', $raw));
        $this->travel(1)->second();
        $this->getJson("/api/messages/$letter->id")->assertOk()->assertJsonPath('data.body_text', 'Order summary')
            ->assertJsonPath('data.html_preview', null);
        $disk->put('mail/elsewhere.eml', $raw);
        $letter->update(['raw_path' => 'mail/elsewhere.eml']);
        $this->travel(1)->second();
        $this->getJson("/admin/messages/$letter->id")->assertOk()->assertJsonPath('letter.body_text', 'Order summary')
            ->assertJsonPath('letter.html_preview', null);
        $letter->update(['raw_path' => $path]);
        $disk->delete($path);
        $this->travel(1)->second();
        $this->getJson("/api/messages/$letter->id")->assertOk()->assertJsonPath('data.body_text', 'Order summary')
            ->assertJsonPath('data.html_preview', null);
    }

    public function test_bcc_delivers_only_to_the_envelope_recipient_using_server_time_and_private_files(): void
    {
        Event::fake([NewMessageReceived::class]);
        $guest = Email::create(['email' => 'guest@mail.test', 'expires_at' => now()->addDay()]);
        Email::create(['email' => 'other@mail.test']);

        self::assertSame(0, $this->deliver('guest@mail.test'));

        $message = Message::query()->retained()->sole();
        self::assertEquals($guest->id, $message->email_id);
        self::assertSame('guest@mail.test', $message->recipient);
        self::assertSame('sender@example.org', $message->from);
        self::assertSame('Привет', $message->subject);
        self::assertTrue(now()->equalTo($message->received_at));
        self::assertSame("Hello & welcome!\nSecond line.", $message->body_text);
        self::assertNull($message->body_html);
        self::assertCount(2, $message->attachments);
        foreach ($message->attachments as $index => $file) {
            self::assertSame('sample.bin', $file['filename']);
            self::assertSame('application/octet-stream', $file['mime']);
            self::assertSame([2, 1][$index], $file['size']);
            self::assertSame(["\x00\xff", 'b'][$index], Storage::disk('local')->get($file['path']));
        }
        self::assertNotSame($message->attachments[0]['path'], $message->attachments[1]['path']);
        self::assertSame([], Storage::disk('public')->allFiles());
        Event::assertDispatched(NewMessageReceived::class, fn ($e) => $e->broadcastOn()[0]->name === "private-mailbox.{$guest->id}"
            && $e->broadcastAs() === 'message.received'
            && $e->broadcastWith() === ['id' => $message->id]);
    }

    public function test_postfix_pipe_retry_preserves_the_original_eml_and_the_existing_delivery_key(): void
    {
        $this->travelTo('2026-11-09 00:00:00 UTC');
        $recipient = 'archive@mail.test';
        $eml = "From: original@example.org\r\nSubject: Original\r\n\r\nFirst line\r\nLast line";
        $bytes = "\x00\n\xff";
        $wire = self::TRACE."Content-Type: multipart/mixed; boundary=outer\r\n\r\n"
            ."--outer\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=forwarded.eml\r\n\r\n$eml\r\n"
            ."--outer\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=data.bin\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode($bytes)."\r\n--outer--\r\n";
        $pipe = str_replace("\r\n", "\n", $wire);
        $pending = Message::create([
            'delivery_key' => hash('sha256', $recipient."\0".$pipe), 'recipient' => $recipient,
            'from' => 'original@example.org', 'received_at' => '2026-11-08 18:04:05', 'is_pending' => true,
        ]);

        self::assertSame(0, $this->deliver($recipient, $pipe, postfix: true));
        $message = Message::sole();
        self::assertFalse($message->is_pending);
        self::assertSame($pending->id, $message->id);
        self::assertSame($eml, Storage::disk('local')->get($message->attachments[0]['path']));
        self::assertSame($bytes, Storage::disk('local')->get($message->attachments[1]['path']));
    }

    public static function mimeAttachments(): array
    {
        $bundle = "Content-Type: multipart/mixed; boundary=docs\r\n"
            ."Content-Disposition: attachment; filename=documents.mime\r\n\r\n"
            ."--docs\r\nContent-Type: text/plain; name=first.txt\r\n\r\nFirst document\r\n"
            ."--docs\r\nContent-Type: application/octet-stream; name=second.bin\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\nAP8=\r\n--docs--";
        $signature = "Content-Type: application/pkcs7-signature\r\n"
            ."Content-Disposition: attachment; filename=smime.p7s\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\nAP8=";

        return [
            'multipart attachment' => ['multipart/mixed', $bundle, 'documents.mime', $bundle],
            'detached signature' => ['multipart/signed; protocol="application/pkcs7-signature"', $signature, 'smime.p7s', "\x00\xff"],
        ];
    }

    #[DataProvider('mimeAttachments')]
    public function test_recipient_can_download_each_delivered_mime_attachment_intact(
        string $outerType, string $part, string $filename, string $expectedBytes
    ): void {
        Event::fake([NewMessageReceived::class]);
        $owner = User::factory()->create(['email' => 'reader@mail.test']);
        $owner->mailbox()->create(['email' => $owner->email]);
        $raw = "From: sender@example.org\r\nMIME-Version: 1.0\r\nContent-Type: $outerType; boundary=outer\r\n\r\n"
            ."--outer\r\nContent-Type: text/plain\r\n\r\nSee attachment.\r\n--outer\r\n$part\r\n--outer--\r\n";

        self::assertSame(0, $this->deliver($owner->email, $raw));
        $this->actingAs($owner);
        $messageId = $this->getJson('/api/messages')->assertOk()->json('data.0.id');
        $attachment = $this->getJson("/api/messages/$messageId")->assertOk()
            ->assertJsonCount(1, 'data.attachments')->json('data.attachments.0');
        $download = $this->get($attachment['url'])->assertOk()->assertDownload($filename);
        self::assertSame($expectedBytes, $download->streamedContent());
    }

    public function test_retries_share_one_delivery_but_distinct_trace_or_recipient_remains_independent(): void
    {
        Event::fake([NewMessageReceived::class]);
        foreach (['first@mail.test', 'FIRST@MAIL.TEST', 'second@mail.test'] as $address) {
            self::assertSame(0, $this->deliver($address));
        }
        self::assertSame(0, $this->deliver('first@mail.test', str_replace('ABCDEF', '012345', self::RAW)));
        self::assertSame(3, Message::count());
        self::assertCount(9, Storage::disk('local')->allFiles());
        Event::assertNotDispatched(NewMessageReceived::class);
    }

    public function test_closed_and_expired_recipients_go_to_archive_while_permanent_stays_active(): void
    {
        Event::fake([NewMessageReceived::class]);
        $old = Email::create(['email' => 'expired@mail.test', 'expires_at' => now()]);
        $closed = Email::create(['email' => 'closed@mail.test', 'closed_at' => now()]);
        $permanent = Email::create(['email' => 'permanent@mail.test']);
        foreach ([$old, $closed, $permanent] as $mailbox) {
            self::assertSame(0, $this->deliver($mailbox->email, "From: a@example.com\r\n\r\nHello"));
        }
        self::assertSame('Hello', Message::where('email_id', $permanent->id)->sole()->body_text);
        self::assertEqualsCanonicalizing([$old->email, $closed->email], Message::whereNull('email_id')->pluck('recipient')->all());
        Event::assertDispatchedTimes(NewMessageReceived::class, 1);
    }

    public function test_rejects_foreign_envelope_empty_input_and_messages_beyond_size_limit(): void
    {
        self::assertSame(67, $this->deliver('other@example.org', bytesRead: $bytesRead));
        self::assertSame(0, $bytesRead);
        self::assertSame(65, $this->deliver('guest@mail.test', ''));
        config(['mailbox.max_message_bytes' => 32]);
        self::assertSame(65, $this->deliver('guest@mail.test', bytesRead: $bytesRead));
        self::assertSame(33, $bytesRead);
        self::assertSame(0, Message::count());
        config(['mailbox.max_message_bytes' => strlen(self::RAW)]);
        self::assertSame(0, $this->deliver('guest@mail.test'));
    }

    public function test_storage_failure_can_retry_without_publishing_an_incomplete_message(): void
    {
        $disk = Storage::disk('local');
        $disk->put('mail', 'occupied');
        config(['logging.default' => 'unwritable', 'logging.channels.unwritable' => [
            'driver' => 'single', 'path' => $disk->path('mail/worker.log'),
        ]]);
        self::assertSame(75, $this->deliver('guest@mail.test'));
        self::assertSame(0, Message::query()->retained()->count());
        $disk->delete('mail');
        self::assertSame(0, $this->deliver('guest@mail.test'));
        self::assertSame(1, Message::query()->retained()->count());
        self::assertCount(3, $disk->allFiles());
    }

    public function test_retry_after_parser_upgrade_refreshes_a_pending_body_and_file_manifest(): void
    {
        $raw = str_replace('--outer--', "--outer\r\nContent-Type: text/plain; name=\"notes.txt\"\r\n\r\nAdditional note.\r\n--outer--", self::RAW);
        $disk = Storage::disk('local');
        $disk->put('mail', 'occupied');
        self::assertSame(75, $this->deliver('guest@mail.test', $raw));
        $pending = Message::sole();
        self::assertNull($pending->body_html);
        $oldFiles = array_slice($pending->attachments, 0, 2);
        $obsoletePath = $oldFiles[0]['path'].'-obsolete';
        $oldFiles[] = [...$oldFiles[0], 'path' => $obsoletePath];
        $pending->update(['body_text' => 'Outdated body', 'body_html' => '<p>Previously stored HTML</p>', 'attachments' => $oldFiles]);
        $disk->delete('mail');
        $disk->put($obsoletePath, 'previous partial copy');

        self::assertSame(0, $this->deliver('guest@mail.test', $raw));
        $delivered = Message::query()->retained()->sole();
        self::assertSame($pending->id, $delivered->id);
        self::assertStringContainsString('Additional note.', $delivered->body_text);
        self::assertSame('<p>Previously stored HTML</p>', $delivered->body_html);
        self::assertCount(3, $delivered->attachments);
        self::assertSame('Additional note.', $disk->get($delivered->attachments[2]['path']));
        $disk->assertMissing($obsoletePath);
    }

    public function test_notification_failure_does_not_defer_or_duplicate_saved_mail(): void
    {
        Email::create(['email' => 'guest@mail.test']);
        Event::listen(NewMessageReceived::class, static fn () => throw new \RuntimeException('Unavailable'));
        self::assertSame(0, $this->deliver('guest@mail.test'));
        self::assertSame(0, $this->deliver('guest@mail.test'));
        self::assertSame(1, Message::query()->retained()->count());
        self::assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_database_queue_publishes_the_letter_and_notification_atomically(): void
    {
        Email::create(['email' => 'guest@mail.test']);
        config(['queue.default' => 'database']);
        Schema::rename('jobs', 'unavailable_jobs');
        self::assertSame(75, $this->deliver('guest@mail.test'));
        self::assertFalse(Message::query()->retained()->exists());

        Schema::rename('unavailable_jobs', 'jobs');
        self::assertSame(0, $this->deliver('guest@mail.test'));
        self::assertSame(0, $this->deliver('guest@mail.test'));
        $this->assertDatabaseCount('jobs', 1);
        self::assertSame(1, Message::query()->retained()->count());
    }

    public function test_a_long_broadcast_outage_retries_and_notifies_the_subscriber_once(): void
    {
        $box = Email::create(['email' => 'queued@mail.test']);
        config(['queue.default' => 'database', 'broadcasting.default' => 'test-outage',
            'broadcasting.connections.test-outage.driver' => 'test-outage']);
        $broadcaster = new class extends NullBroadcaster
        {
            public int $attempts = 0;
            public array $delivered = [];

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                if (++$this->attempts <= 6) {
                    throw new \RuntimeException('Reverb is restarting');
                }
                $this->delivered[] = [(string) $channels[0], $event, $payload['id']];
            }
        };
        Broadcast::extend('test-outage', fn () => $broadcaster);
        self::assertSame(0, $this->deliver($box->email));
        $options = new WorkerOptions(sleep: 0, maxTries: 5);
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            app('queue.worker')->runNextJob('database', 'default', $options);
            self::assertSame($attempt, $broadcaster->attempts);
            $this->travel(5)->minutes();
        }
        self::assertSame([['private-mailbox.'.$box->id, 'message.received', Message::sole()->id]], $broadcaster->delivered);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_postgres_exception_details_cannot_copy_private_mail_into_application_logs(): void
    {
        $log = Storage::disk('local')->path('delivery.log');
        config(['logging.default' => 'mailtest', 'logging.channels.mailtest' => ['driver' => 'single', 'path' => $log]]);
        Message::creating(static fn () => throw new \RuntimeException('SQL error including sensitive message body'));

        self::assertSame(75, $this->deliver('archive@mail.test'));
        self::assertFileExists($log);
        self::assertStringNotContainsString('sensitive message body', file_get_contents($log));
    }

    public function test_prune_keeps_the_row_for_retry_after_partial_filesystem_failure(): void
    {
        self::assertSame(0, $this->deliver('archive@mail.test'));
        $this->travel(365)->days();
        $disk = Storage::disk('local');
        $path = Message::sole()->attachments[1]['path'];
        $disk->delete($path);
        $disk->put($path.'/obstruction', 'occupied');

        $this->artisan('mail:prune')->assertFailed();
        self::assertSame(1, Message::count());
        self::assertSame(0, Message::query()->retained()->count());
        $disk->deleteDirectory($path);
        $this->artisan('mail:prune')->assertSuccessful();
        self::assertSame(0, Message::count());
        self::assertSame([], $disk->allFiles());
    }

    public function test_queued_notification_skips_a_mailbox_closed_before_broadcast(): void
    {
        $mailbox = Email::create(['email' => 'guest@mail.test', 'expires_at' => now()->addDay()]);
        $event = new NewMessageReceived($mailbox->id, 1);
        $mailbox->update(['closed_at' => now()]);

        self::assertSame([], $event->broadcastOn());
    }

    public function test_pruning_uses_inclusive_boundary_across_mailbox_types_and_preserves_empty_permanent_mailbox(): void
    {
        $guest = Email::create(['email' => 'guest@mail.test', 'expires_at' => now()->addDay()]);
        $permanent = Email::create(['email' => 'permanent@mail.test']);
        foreach ([$guest->email, $permanent->email, 'archive@mail.test'] as $address) {
            self::assertSame(0, $this->deliver($address));
        }
        $this->travel(365)->days();
        $this->travelTo(now()->subSecond());
        self::assertSame(3, Message::query()->retained()->count());
        $this->artisan('mail:prune')->assertSuccessful();
        self::assertCount(9, Storage::disk('local')->allFiles());
        $this->travel(1)->seconds();
        self::assertSame(0, Message::query()->retained()->count());
        self::assertSame(0, $this->deliver($permanent->email, "From: new@example.org\n\nNew"));
        $this->artisan('mail:prune')->assertSuccessful();
        self::assertSame('New', Message::sole()->body_text);
        self::assertSame([Message::sole()->raw_path], Storage::disk('local')->allFiles());
        self::assertNotNull($guest->fresh());
        self::assertNotNull($permanent->fresh());
        $this->travel(365)->days();
        $this->artisan('mail:prune')->assertSuccessful();
        self::assertSame(0, Message::count());
        self::assertNull($guest->fresh());
        self::assertNotNull($permanent->fresh());
    }
}
