<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\MailboxDomain;
use App\Models\Message;
use App\Services\EmailGeneratorService;
use App\Services\MailboxSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Random\Engine;
use Random\Randomizer;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_can_issue_on_either_enabled_domain(): void
    {
        MailboxDomain::query()->update(['issuance_enabled' => false]);
        MailboxDomain::create(['name' => 'a.test', 'issuance_enabled' => true]);
        MailboxDomain::create(['name' => 'receive.test']);
        $last = MailboxDomain::create(['name' => 'b.test', 'issuance_enabled' => true]);
        $this->app->instance(Randomizer::class, new Randomizer(new class implements Engine
        {
            private int $next = 0;

            public function generate(): string
            {
                return pack('V', $this->next++);
            }
        }));
        foreach (['a.test', 'b.test'] as $domain) {
            $this->flushSession();
            $first = $this->getJson('/api/email')->assertOk()->json('data');
            $this->assertMatchesRegularExpression('/\A[a-z0-9]{14}@'.preg_quote($domain, '/').'\z/', $first['email']);
            $this->getJson('/api/email')->assertOk()->assertExactJson(['data' => $first]);
        }
        $last->update(['issuance_enabled' => false]);
        $this->getJson('/api/email')->assertOk()->assertExactJson(['data' => $first]);
        $this->flushSession();
        $this->assertStringEndsWith('@a.test', $this->getJson('/api/email')->assertOk()->json('data.email'));
    }

    public function test_options_reflect_issuable_formats_without_creating_an_address_or_using_quota(): void
    {
        MailboxDomain::query()->update(['issuance_enabled' => false]);
        $this->getJson('/api/email/options')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/email')->assertServiceUnavailable()->assertJsonPath('code', 'no_issuable_domains');
        $long = str_repeat(str_repeat('a', 63).'.', 3).str_repeat('b', 60);
        MailboxDomain::create(['name' => $long, 'issuance_enabled' => true]);
        MailboxDomain::create(['name' => 'b.test', 'issuance_enabled' => true]);
        $this->getJson('/api/email/options')->assertOk()->assertExactJson(['data' => [
            ['domain' => $long, 'formats' => ['random']],
            ['domain' => 'b.test', 'formats' => ['random', 'words']],
        ]]);
        $this->assertDatabaseCount('emails', 0);
        $this->assertSame(0, RateLimiter::attempts(MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1')));
    }

    public function test_explicit_fields_and_format_only_requests_use_eligible_domains(): void
    {
        MailboxDomain::query()->update(['issuance_enabled' => false]);
        $long = str_repeat(str_repeat('a', 63).'.', 3).str_repeat('b', 60);
        MailboxDomain::create(['name' => $long, 'issuance_enabled' => true]);
        MailboxDomain::create(['name' => 'a.test', 'issuance_enabled' => true]);
        config(['mailbox_words.first' => ['mint'], 'mailbox_words.second' => ['fox']]);
        $first = $this->postJson('/api/emails', ['domain' => $long])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/\A[a-z0-9]@'.preg_quote($long, '/').'\z/', $first['email']);
        $next = $this->postJson('/api/emails', ['format' => 'words'], ['X-Expected-Mailbox-Id' => $first['id']])
            ->assertCreated()->assertJsonPath('data.email', 'mint.fox@a.test')->json('data.id');
        MailboxDomain::create(['name' => 'b.test', 'issuance_enabled' => true]);
        $this->postJson('/api/emails', ['domain' => 'b.test', 'format' => 'words'], ['X-Expected-Mailbox-Id' => $next])
            ->assertCreated()->assertJsonPath('data.email', 'mint.fox@b.test');
        $this->assertSame(3, RateLimiter::attempts(MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1')));
    }

    public function test_invalid_and_withdrawn_choices_preserve_mail_messages_and_quota(): void
    {
        $domain = MailboxDomain::sole();
        $response = $this->getJson('/api/email')->assertOk();
        $mailbox = Email::findOrFail($response->json('data.id'));
        $letter = $mailbox->messages()->create(['recipient' => $mailbox->email, 'subject' => 'Saved', 'from' => 'a@test.invalid', 'received_at' => now()]);
        $headers = ['X-Expected-Mailbox-Id' => $mailbox->id];
        $domain->update(['issuance_enabled' => false]);
        $long = str_repeat(str_repeat('a', 63).'.', 3).str_repeat('b', 60);
        MailboxDomain::create(['name' => $long, 'issuance_enabled' => true]);
        foreach ([
            [['domain' => $domain->name], 'domain'], [['domain' => 'missing.test'], 'domain'],
            [['format' => 'words', 'domain' => $long], 'format'], [['format' => 'words'], 'format'],
            [['format' => 'other'], 'format'], [['format' => null], 'format'], [['format' => []], 'format'],
            [['domain' => null], 'domain'], [['domain' => ['a.test']], 'domain'],
        ] as [$fields, $error]) {
            $this->postJson('/api/emails', $fields, $headers)->assertUnprocessable()->assertJsonValidationErrors($error);
        }
        MailboxDomain::query()->update(['issuance_enabled' => false]);
        $this->postJson('/api/emails', [], $headers)->assertServiceUnavailable()->assertJsonPath('code', 'no_issuable_domains');
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $mailbox->id);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.id', $letter->id);
        $this->assertSame(1, RateLimiter::attempts(MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1')));
    }

    public function test_exhausting_distinct_candidates_keeps_the_current_address_and_quota(): void
    {
        $domain = MailboxDomain::sole()->name;
        $first = $this->getJson('/api/email')->assertOk()->json('data.id');
        $seconds = ['fox', 'hen', 'owl', 'bee', 'ant'];
        foreach ($seconds as $second) {
            Email::create(['email' => 'mint.'.$second.'@'.$domain, 'closed_at' => now()]);
        }
        foreach ([$seconds, ['fox']] as $available) {
            config(['mailbox_words.first' => ['mint'], 'mailbox_words.second' => $available]);
            $this->postJson('/api/emails', ['format' => 'words'], ['X-Expected-Mailbox-Id' => $first])
                ->assertServiceUnavailable()->assertJsonPath('code', 'mailbox_candidates_exhausted');
            $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $first);
        }
        $this->assertNull(Email::findOrFail($first)->closed_at);
        $this->assertDatabaseCount('emails', 6);
        $this->assertSame(1, RateLimiter::attempts(MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1')));
    }

    public function test_rotation_uses_the_next_candidate_after_a_collision_and_counts_one_issue(): void
    {
        $domain = MailboxDomain::sole()->name;
        $first = $this->getJson('/api/email')->assertOk()->json('data.id');
        $previous = Email::findOrFail($first);
        $next = 'free@'.$domain;
        $this->partialMock(EmailGeneratorService::class)->shouldReceive('candidates')
            ->once()->with($domain, 'random')->andReturn([$previous->email, $next]);

        $rotation = $this->postJson('/api/emails', ['domain' => $domain], ['X-Expected-Mailbox-Id' => $first])
            ->assertCreated()->assertJsonPath('data.email', $next);
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $rotation->json('data.id'));
        $this->assertNotNull($previous->fresh()->closed_at);
        $this->assertDatabaseCount('emails', 2);
        $this->assertSame(2, RateLimiter::attempts(MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1')));
    }

    public function test_visits_keep_the_same_mailbox_until_the_exact_24_hour_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        $response = $this->getJson('/api/email')->assertOk()->assertJsonStructure(['data' => ['id', 'email', 'expires_at']]);
        $first = $response->json('data');
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        $this->assertEquals(now()->addDay(), new \DateTimeImmutable($first['expires_at']));
        $this->travel(24 * 60 * 60 - 1)->seconds();
        $this->getJson('/api/email')->assertOk()->assertExactJson(['data' => $first]);
        $this->travel(1)->second();
        $this->assertNotSame($first['id'], $this->getJson('/api/email')->assertSuccessful()->json('data.id'));
    }

    public function test_a_rotated_guest_keeps_access_after_the_original_browser_session_cookie_expires(): void
    {
        $this->freezeSecond();
        $cookie = config('session.cookie');
        $first = $this->getJson('/api/email')->assertOk();
        $this->useBrowserCookies($first);
        $this->travel(20)->hours();
        $second = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $first->json('data.id')])
            ->assertCreated()->assertCookieMissing($cookie)->assertCookieMissing('XSRF-TOKEN');
        $this->flushSession();
        unset($this->unencryptedCookies[$cookie]);
        $this->getJson('/api/messages')->assertOk()->assertHeader('X-Mailbox-Id', '0');
        $this->travel(23)->hours();

        $this->useBrowserCookies($first, $second);
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $second->json('data.id'))
            ->assertCookie($cookie)->assertCookie('XSRF-TOKEN');
        $this->assertDatabaseCount('emails', 2);
    }

    public function test_guest_cookie_recovery_observes_a_shorter_session_lifetime_on_the_server(): void
    {
        config(['session.lifetime' => 10]);
        $this->freezeSecond();
        $issued = $this->getJson('/api/email')->assertOk();
        $name = config('session.cookie').'_mailbox_'.$issued->json('data.id').'_'.now()->addMinutes(10)->timestamp;
        $issued->assertCookie($name);
        $recovery = $issued->getCookie($name, false);
        $this->assertTrue($recovery->isHttpOnly());
        $this->assertSame(now()->addMinutes(10)->timestamp, $recovery->getExpiresTime());

        $this->flushSession();
        $this->withCredentials()->withUnencryptedCookie($name, $recovery->getValue());
        $this->travel(10)->minutes();
        $this->getJson('/api/messages')->assertOk()->assertHeader('X-Mailbox-Id', '0');
    }

    public function test_a_delayed_first_rotation_response_cannot_shorten_or_replace_a_later_issue(): void
    {
        $this->freezeSecond();
        $first = $this->getJson('/api/email')->assertOk();
        $this->useBrowserCookies($first);
        $this->travel(20)->hours();
        $delayed = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $first->json('data.id')])->assertCreated();
        $seen = $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $delayed->json('data.id'));
        $this->useBrowserCookies($first, $seen);
        $this->travel(1)->hour();
        $latest = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $seen->json('data.id')])->assertCreated();
        $this->travel(23 * 60 + 15)->minutes();
        $this->useBrowserCookies($first, $seen, $latest, $delayed);
        $this->getJson('/api/messages')->assertOk()->assertHeader('X-Mailbox-Id', (string) $latest->json('data.id'));
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $latest->json('data.id'));
        $this->assertDatabaseCount('emails', 3);
    }

    public function test_active_guest_reads_renew_access_across_multiple_short_session_lifetimes(): void
    {
        config(['session.lifetime' => 10]);
        $this->freezeSecond();
        $issued = $this->getJson('/api/email')->assertOk();
        $responses = [$issued];
        for ($i = 0; $i < 4; $i++) {
            $this->travel(6)->minutes();
            $this->useBrowserCookies(...$responses);
            $responses[] = $this->getJson('/api/messages')->assertOk()
                ->assertHeader('X-Mailbox-Id', (string) $issued->json('data.id'));
            $this->useBrowserCookies(...$responses);
            $prefix = config('session.cookie').'_mailbox_';
            $this->assertCount(1, array_filter(array_keys($this->unencryptedCookies), fn ($name) => str_starts_with($name, $prefix)));
        }
    }

    public function test_a_delayed_read_preserves_the_latest_idle_deadline_for_the_same_mailbox(): void
    {
        config(['session.lifetime' => 10]);
        $this->freezeSecond();
        $issued = $this->getJson('/api/email')->assertOk();
        $this->useBrowserCookies($issued);
        $this->travel(4)->minutes();
        $delayed = $this->getJson('/api/messages')->assertOk();
        $this->travel(2)->minutes();
        $latest = $this->getJson('/api/messages')->assertOk();
        $this->travel(9)->minutes();
        $this->useBrowserCookies($issued, $latest, $delayed);
        $this->getJson('/api/messages')->assertOk()->assertHeader('X-Mailbox-Id', (string) $issued->json('data.id'));
    }

    private function useBrowserCookies(TestResponse ...$responses): void
    {
        $this->flushSession();
        $this->withCredentials();
        $this->unencryptedCookies = [];
        foreach ($responses as $response) {
            foreach ($response->headers->getCookies() as $cookie) {
                $expires = $cookie->getExpiresTime();
                if ($expires === 0 || $expires > now()->timestamp) {
                    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
                } else {
                    unset($this->unencryptedCookies[$cookie->getName()]);
                }
            }
        }
    }

    public function test_rotation_and_deletion_close_old_addresses_and_keep_the_archive(): void
    {
        $response = $this->getJson('/api/email')->assertSuccessful();
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        $first = $response->json('data.id');
        $email = Email::findOrFail($first);
        $letter = $email->messages()->create(['recipient' => $email->email, 'from' => 'sender@example.test', 'subject' => 'Private', 'received_at' => now()]);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.id', $letter->id);
        $second = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $first])->assertSuccessful()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertNotNull($email->fresh()->closed_at);
        $this->getJson('/api/messages')->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);
        $this->deleteJson('/api/email', headers: ['X-Expected-Mailbox-Id' => $second])->assertNoContent();
        $this->assertNotNull(Email::findOrFail($second)->closed_at);
        $this->assertDatabaseHas('messages', ['id' => $letter->id, 'email_id' => $first]);
        $this->assertNotSame($second, $this->getJson('/api/email')->json('data.id'));
    }

    public function test_inbox_and_downloads_enforce_session_ownership_and_exact_retention_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        Storage::fake('local');
        Storage::disk('local')->put('letters/1', 'private content');
        $email = Email::create(['email' => 'reader@test.invalid', 'expires_at' => now()->addDay()]);
        $record = ['recipient' => $email->email, 'from' => 'sender@example.test', 'subject' => 'Paper',
            'received_at' => now()->subDays(365)->addSecond(),
            'attachments' => [['path' => 'letters/1', 'filename' => 'letter.txt', 'mime' => 'text/plain', 'size' => 15]]];
        $letter = $email->messages()->create($record);
        $download = "/api/messages/{$letter->id}/attachments/0";
        $this->getJson('/api/messages')->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);
        $this->getJson("/api/messages/{$letter->id}")->assertNotFound();
        $this->get($download)->assertNotFound();
        $this->withSession(['email_id' => $email->id]);
        $deadline = now()->addSecond()->toISOString();
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_attachments', true)
            ->assertJsonPath('data.0.expires_at', $deadline);
        $detail = $this->getJson("/api/messages/{$letter->id}")->assertOk()->assertHeader('X-Mailbox-Id', (string) $email->id)
            ->assertJsonPath('data.expires_at', $deadline);
        $this->assertSame($download, parse_url($detail->json('data.attachments.0.url'), PHP_URL_PATH));
        $this->assertArrayNotHasKey('path', $detail->json('data.attachments.0'));
        $this->get($download)->assertDownload('letter.txt');
        $this->get("/api/messages/{$letter->id}/attachments/1")->assertNotFound();
        $this->travel(1)->second();
        $this->getJson('/api/messages')->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);
        $this->getJson("/api/messages/{$letter->id}")->assertNotFound();
        $this->get($download)->assertNotFound();
        $this->assertSame(1, Message::count());
    }

    public function test_inbox_snapshot_detects_a_delayed_delivery_even_when_another_letter_expires(): void
    {
        $this->freezeTime();
        $email = Email::findOrFail($this->getJson('/api/email')->assertOk()->json('data.id'));
        $fields = ['from' => 'sender@example.test', 'recipient' => $email->email, 'subject' => 'Mail'];
        $email->messages()->create([...$fields, 'received_at' => now()->subDays(365)->addSecond()]);
        $pending = $email->messages()->create([...$fields, 'is_pending' => true, 'received_at' => now()->subDays(2)]);
        $newest = $email->messages()->create([...$fields, 'received_at' => now()]);
        $snapshot = fn () => $this->getJson('/api/email')->assertOk()->assertHeader('X-Inbox-Snapshot')
            ->headers->get('X-Inbox-Snapshot');
        $before = $snapshot();
        $this->assertSame($before, $snapshot());

        $this->travel(1)->second();
        $pending->update(['is_pending' => false]);
        $this->getJson('/api/messages')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->id)->assertJsonPath('data.1.id', $pending->id);
        $this->assertNotSame($before, $snapshot());
    }

    public function test_list_paginates_headers_with_stable_cursors_and_fetches_only_the_opened_body(): void
    {
        $email = Email::create(['email' => 'reader@test.invalid', 'expires_at' => now()->addDay()]);
        $other = Email::create(['email' => 'other@test.invalid', 'expires_at' => now()->addDay()]);
        $ids = [];
        $received = now()->microsecond(123400);
        $fields = ['from' => 'sender@example.test', 'body_text' => 'private body',
            'received_at' => $received, 'recipient' => $email->email];
        foreach (range(1, 33) as $i) {
            $ids[] = $email->messages()->create([...$fields, 'subject' => (string) $i])->id;
        }
        $foreign = $other->messages()->create([...$fields, 'recipient' => $other->email, 'subject' => 'Other']);
        $this->withSession(['email_id' => $email->id]);
        $page = $this->getJson('/api/messages')->assertOk()->assertHeader('X-Mailbox-Id', (string) $email->id)->assertJsonCount(30, 'data');
        $expected = array_reverse($ids);
        $this->assertSame(array_slice($expected, 0, 30), array_column($page->json('data'), 'id'));
        $this->assertEqualsCanonicalizing(['id', 'from', 'subject', 'received_at', 'expires_at', 'has_attachments'], array_keys($page->json('data.0')));
        $page->assertJsonPath('data.0.has_attachments', false);
        $cursor = $page->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $email->messages()->create([...$fields, 'subject' => 'just arrived']);
        $older = $this->getJson('/api/messages?cursor='.rawurlencode($cursor))->assertOk()->assertJsonPath('meta.next_cursor', null);
        $this->assertSame(array_slice($expected, 30), array_column($older->json('data'), 'id'));
        $this->getJson('/api/messages/'.$ids[0])->assertOk()->assertJsonPath('data.body_text', 'private body');
        $this->getJson('/api/messages/'.$foreign->id)->assertNotFound();
        $this->deleteJson('/api/email', headers: ['X-Expected-Mailbox-Id' => $email->id])->assertNoContent();
        $this->getJson('/api/messages/'.$ids[0])->assertNotFound();
    }

    public function test_issuance_closure_and_expiration_keep_microseconds(): void
    {
        $this->travelTo('2026-11-20 10:00:00.432100 UTC');
        $issued = $this->getJson('/api/email')->assertOk();
        $email = Email::findOrFail($issued->json('data.id'));
        $this->assertSame('432100', $email->created_at->format('u'));
        $this->assertSame('432100', $email->expires_at->format('u'));
        $this->assertSame('432100', (new \DateTimeImmutable($issued->json('data.expires_at')))->format('u'));
        $this->travelTo('2026-11-21 10:00:00.432099 UTC');
        $this->getJson('/api/email')->assertJsonPath('data.id', $email->id);
        $this->travelTo('2026-11-21 10:00:00.432100 UTC');
        $nextId = $this->getJson('/api/email')->assertOk()->json('data.id');
        $this->assertNotSame($email->id, $nextId);
        $this->travelTo('2026-11-21 10:00:00.876543 UTC');
        $this->deleteJson('/api/email', headers: ['X-Expected-Mailbox-Id' => $nextId])->assertNoContent();
        $this->assertSame('876543', Email::findOrFail($nextId)->closed_at->format('u'));
    }

    public function test_broadcast_authorization_signs_only_the_active_mailbox_of_this_session(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'mailmm',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        $email = Email::create(['email' => 'private@test.invalid', 'expires_at' => now()->addDay()]);
        $channel = ['channel_name' => 'private-mailbox.'.$email->id, 'socket_id' => '123.456'];
        $this->postJson('/api/broadcasting/auth', $channel)->assertForbidden();
        $this->withSession(['email_id' => $email->id]);
        $signed = $this->postJson('/api/broadcasting/auth', $channel)->assertOk()->assertJsonStructure(['channel_data']);
        $this->assertSame('test-key:'.hash_hmac('sha256', '123.456:'.$channel['channel_name'].':'.$signed->json('channel_data'), 'test-secret'), $signed->json('auth'));
        $this->postJson('/api/broadcasting/auth', [...$channel, 'channel_name' => 'private-mailbox.99999'])->assertForbidden();
        $this->postJson('/api/broadcasting/auth', [...$channel, 'socket_id' => 'malformed'])->assertUnprocessable();
        $this->travel(24)->hours();
        $this->postJson('/api/broadcasting/auth', $channel)->assertForbidden();
    }

    public function test_reading_a_page_then_reopening_the_mailbox_uses_the_same_cookie_as_the_api(): void
    {
        $this->withoutVite();
        $cookie = config('session.cookie');
        $alice = $this->getJson('/api/email')->assertOk();
        $aliceCookie = $alice->getCookie($cookie)->getValue();
        $this->flushSession();
        $bob = $this->getJson('/api/email')->assertOk();
        $bobCookie = $bob->getCookie($cookie)->getValue();
        $this->assertNotSame($alice->json('data.id'), $bob->json('data.id'));

        $this->flushSession();
        $page = $this->withCookie($cookie, $aliceCookie)->get('/help')->assertOk();
        $this->flushSession();
        $this->withCredentials()->withCookie($cookie, $page->getCookie($cookie)->getValue())
            ->getJson('/api/email')->assertJsonPath('data.id', $alice->json('data.id'));
        $this->flushSession();
        $this->withCookie($cookie, $bobCookie)->getJson('/api/messages?email_id='.$alice->json('data.id'))
            ->assertHeader('X-Mailbox-Id', (string) $bob->json('data.id'));
    }

    public function test_cross_site_mutations_require_the_web_session_csrf_token(): void
    {
        $this->app->instance('env', 'local');
        $response = $this->get('/api/email')->assertOk();
        $cookie = config('session.cookie');
        $this->withCredentials()->withCookie($cookie, $response->getCookie($cookie)->getValue());
        $this->postJson('/api/emails', headers: ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(419);
        $this->deleteJson('/api/email', headers: ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(419);
        $this->getJson('/api/email')->assertJsonPath('data.id', $response->json('data.id'));
        $this->postJson('/api/emails', headers: ['X-XSRF-TOKEN' => $response->getCookie('XSRF-TOKEN', false)->getValue(), 'X-Expected-Mailbox-Id' => $response->json('data.id')])
            ->assertCreated();
    }

    public function test_stale_confirmation_preserves_current_mailbox_and_issuance_quota(): void
    {
        $issued = $this->getJson('/api/email')->assertOk();
        $cookie = config('session.cookie');
        $this->withCredentials()->withCookie($cookie, $issued->getCookie($cookie)->getValue());
        $first = ['X-Expected-Mailbox-Id' => (string) $issued->json('data.id')];
        $second = $this->postJson('/api/emails', headers: $first)->assertCreated()->json('data.id');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/emails', headers: $first)->assertConflict();
        }
        $this->postJson('/api/emails')->assertConflict();
        $this->deleteJson('/api/email', headers: $first)->assertConflict();
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $second);
        $this->assertDatabaseCount('emails', 2);
        $this->assertNull(Email::findOrFail($second)->closed_at);
        $this->travel(1)->second();
        $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $second])->assertCreated();
    }

    public function test_last_rotation_can_be_recovered_with_the_persisted_legacy_session(): void
    {
        $previous = Email::create(['email' => 'old@test.invalid', 'expires_at' => now()->addDay()]);
        $this->withSession(['email_id' => $previous->id]);
        $issued = $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $previous->id);
        $cookie = config('session.cookie');
        $id = $issued->getCookie($cookie)->getValue();
        $this->withCredentials()->withCookie($cookie, $id);
        $session = $this->app['session']->driver();
        $beforeRotation = $session->getHandler()->read($id);
        $key = MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1');
        RateLimiter::increment($key, 3600, 9);
        $oldHeader = ['X-Expected-Mailbox-Id' => $previous->id];
        $next = $this->postJson('/api/emails', headers: $oldHeader)->assertCreated()->json('data.id');

        $session->getHandler()->write($id, $beforeRotation);
        $session->flush();
        $this->postJson('/api/emails', headers: $oldHeader)->assertConflict();
        $this->deleteJson('/api/email', headers: $oldHeader)->assertConflict();
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $next);
        $this->assertDatabaseCount('emails', 2);
        $this->assertSame(10, RateLimiter::attempts($key));
        $this->assertNotNull($previous->fresh()->closed_at);
    }

    public function test_existing_browser_session_recovers_first_issue_after_a_lost_session_write(): void
    {
        $cookie = config('session.cookie');
        $id = $this->getJson('/api/email/options')->assertOk()->getCookie($cookie)->getValue();
        $this->withCredentials()->withCookie($cookie, $id);
        $session = $this->app['session']->driver();
        $beforeIssue = $session->getHandler()->read($id);
        $issued = $this->getJson('/api/email')->assertOk()->json('data.id');
        $session->getHandler()->write($id, $beforeIssue);
        $session->flush();
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $issued);
        $this->assertDatabaseCount('emails', 1);
    }

    public function test_competing_issue_after_quota_precheck_rolls_back_only_the_losing_mailbox(): void
    {
        $current = $this->getJson('/api/email')->assertOk()->json('data.id');
        $key = MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1');
        RateLimiter::increment($key, 3600, 8);
        Email::created(function () use ($key) {
            $this->assertSame(10, RateLimiter::increment($key, 3600));
        });
        $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $current])
            ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('code', 'guest_issuance_quota_exceeded');
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $current);
        $this->assertNull(Email::findOrFail($current)->closed_at);
        $this->assertDatabaseCount('emails', 1);
    }
}
