<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\Message;
use App\Models\User;
use App\Services\MailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PanelDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function sessionDrivers(): array
    {
        return [['array'], ['cookie']];
    }

    #[DataProvider('sessionDrivers')]
    public function test_delayed_json_responses_cannot_restore_a_previous_login(string $driver): void
    {
        config(['session.driver' => $driver, 'session.domain' => 'localhost', 'session.path' => '/']);
        $sessionName = config('session.cookie');
        $first = $this->getJson('/api/email')->assertOk()->assertCookie($sessionName)->assertCookie('XSRF-TOKEN');
        $guestId = $first->getCookie($sessionName)->getValue();
        $this->acceptBrowserCookies($first);

        $admin = User::factory()->create(['is_admin' => true]);
        $login = $this->postJson('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertCookie($sessionName)->assertCookie('XSRF-TOKEN');
        $accountId = $login->getCookie($sessionName)->getValue();
        $this->assertNotSame($guestId, $accountId);
        $this->acceptBrowserCookies($login);

        foreach (['/admin/archive', '/admin/session', '/api/email'] as $path) {
            $reading = $this->getJson($path)->assertOk()->assertCookieMissing($sessionName)->assertCookieMissing('XSRF-TOKEN');
            if ($driver === 'cookie') {
                $reading->assertCookie($accountId);
            }
            $this->acceptBrowserCookies($reading);
        }
        $this->patchJson('/admin/settings/issuance', ['guest_issuance_enabled' => true])
            ->assertOk()->assertCookieMissing($sessionName)->assertCookieMissing('XSRF-TOKEN');
        $this->get('/admin/archive')->assertOk()->assertCookie($sessionName)->assertCookie('XSRF-TOKEN');

        $changed = $this->putJson('/admin/settings/password', [
            'current_password' => 'password',
            'password' => 'new-admin-password',
            'password_confirmation' => 'new-admin-password',
        ])->assertOk()->assertCookie($sessionName)->assertCookie('XSRF-TOKEN');
        $changedId = $changed->getCookie($sessionName)->getValue();
        $this->assertNotSame($accountId, $changedId);
        $this->acceptBrowserCookies($changed);
        $this->getJson('/admin/session')->assertJsonPath('user.id', $admin->id);

        $logout = $this->postJson('/admin/logout')->assertOk()->assertCookie($sessionName)->assertCookie('XSRF-TOKEN');
        $this->assertNotSame($changedId, $logout->getCookie($sessionName)->getValue());
        $this->acceptBrowserCookies($logout);
        $this->getJson('/admin/session')->assertExactJson(['user' => null]);
    }

    private function acceptBrowserCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue() ?? '');
        }
        $this->withCredentials();
        Auth::forgetGuards();
        $this->flushSession();
    }

    public function test_guest_access_survives_a_panel_session_and_the_api_uses_server_time(): void
    {
        $this->travelTo('2026-11-21 09:30:00.123456 UTC');
        $boxResponse = $this->getJson('/api/email')->assertOk()
            ->assertHeaderMissing('X-Panel-Url')
            ->assertHeader('X-Server-Time', now()->toISOString());
        $cookie = config('session.cookie');
        $this->withCredentials()->withCookie($cookie, $boxResponse->getCookie($cookie)->getValue());
        $guest = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $boxResponse->json('data.id')])->assertCreated()
            ->assertHeader('X-Server-Time', now()->toISOString())->json('data.id');
        $owner = User::factory()->create(['password' => 'local-owner-secret']);
        $permanent = $owner->mailbox()->create(['email' => $owner->email]);
        $login = $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'local-owner-secret'])->assertOk()->assertJsonPath('redirect', '/admin');
        $this->withCookie($cookie, $login->getCookie($cookie)->getValue());
        $this->getJson('/api/email')->assertJsonPath('data.id', $permanent->id)->assertHeader('X-Panel-Url', '/admin');
        $logout = $this->postJson('/admin/logout')->assertOk()->assertJsonStructure(['redirect']);
        $this->withCookie($cookie, $logout->getCookie($cookie)->getValue());
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $guest)->assertHeaderMissing('X-Panel-Url');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_an_admin_can_return_to_the_panel_even_when_no_mailbox_can_be_issued(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $mailboxId = $this->actingAs($admin)->getJson('/api/email')->assertOk()
            ->assertHeader('X-Panel-Url', '/admin')->json('data.id');
        $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $mailboxId])
            ->assertCreated()->assertHeader('X-Panel-Url', '/admin');
        $this->patchJson('/admin/settings/issuance', ['guest_issuance_enabled' => 0])->assertOk()->assertJsonStructure(['redirect']);
        $this->useBrowserSession(Str::random(40));
        $this->actingAs($admin)->getJson('/api/email')->assertServiceUnavailable()->assertHeader('X-Panel-Url', '/admin');
    }

    public function test_new_session_issue_and_rotation_share_a_quota_separate_from_panel_login(): void
    {
        $first = $this->getJson('/api/email')->assertOk()->json('data.id');
        for ($i = 1; $i < 10; $i++) {
            $this->flushSession();
            $this->getJson('/api/email')->assertOk();
        }
        $this->flushSession();
        $this->getJson('/api/email')->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertJsonPath('code', 'guest_issuance_quota_exceeded');
        $this->withSession(['email_id' => $first])->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $first);
        $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $first])->assertTooManyRequests();
        $this->assertNull(Email::findOrFail($first)->closed_at);
        $admin = User::factory()->create(['password' => 'test-account-password', 'is_admin' => true]);
        $this->postJson('/admin/login', ['email' => $admin->email, 'password' => 'test-account-password'])->assertOk()->assertJsonPath('redirect', '/admin');
    }

    public function test_panel_session_regeneration_preserves_the_rotated_guest_mailbox(): void
    {
        $cookie = config('session.cookie');
        $guest = $this->getJson('/api/email')->assertOk();
        $this->useBrowserSession($guest->getCookie($cookie)->getValue());
        $next = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $guest->json('data.id')])
            ->assertCreated()->json('data.id');
        $owner = User::factory()->create(['password' => 'test-account-password']);
        $permanent = $owner->mailbox()->create(['email' => $owner->email]);
        $login = $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'test-account-password'])->assertOk();
        $this->useBrowserSession($login->getCookie($cookie)->getValue());
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $permanent->id);
        $logout = $this->postJson('/admin/logout')->assertOk();
        $this->useBrowserSession($logout->getCookie($cookie)->getValue());
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $next);
        $this->assertDatabaseCount('emails', 3);
    }

    public function test_overview_counts_real_deliveries_and_active_mailboxes_at_utc_boundaries(): void
    {
        $this->travelTo('2026-11-21 09:30:00.123456 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $permanent = $owner->mailbox()->create(['email' => $owner->email]);
        Email::create(['email' => 'guest@test.invalid', 'expires_at' => now()->addDay()]);
        Email::create(['email' => 'closed@test.invalid', 'expires_at' => now()->addDay(), 'closed_at' => now()]);
        Email::create(['email' => 'expired@test.invalid', 'expires_at' => now()]);

        $fields = ['recipient' => 'test@example.test', 'from' => 'sender@example.test'];
        $latest = Message::create([...$fields, 'subject' => 'New arrival', 'received_at' => now()]);
        $permanent->messages()->create([...$fields, 'received_at' => now()->subDay()]);
        Message::create([...$fields, 'received_at' => now()->subDay()->subMicrosecond()]);
        $permanent->messages()->create([...$fields, 'received_at' => now()->subDays(6)->startOfDay()]);
        Message::create([...$fields, 'received_at' => now()->subDays(6)->startOfDay()->subMicrosecond()]);
        Message::create([...$fields, 'received_at' => now(), 'is_pending' => true]);
        Message::create([...$fields, 'received_at' => now()->subDays(365)]);

        $overview = $this->actingAs($admin)->getJson('/admin')->assertOk()->assertJsonPath('screen', 'dashboard');
        $this->assertSame(['received24h' => 2, 'archived24h' => 1, 'guests' => 1, 'permanent' => 1, 'activeUsers' => null], $overview->json('metrics'));
        $days = $overview->json('days');
        $this->assertCount(7, $days);
        $this->assertSame(['date' => '2026-11-15', 'mailbox' => 1, 'archive' => 0], $days[0]);
        $this->assertSame(['date' => '2026-11-16', 'mailbox' => 0, 'archive' => 0], $days[1]);
        $this->assertSame(['date' => '2026-11-20', 'mailbox' => 1, 'archive' => 1], $days[5]);
        $this->assertSame(['date' => '2026-11-21', 'mailbox' => 0, 'archive' => 1], $days[6]);
        $this->assertSame($latest->id, $overview->json('recentMessages.0.id'));
        $this->assertCount(5, $overview->json('recentMessages'));
        $overview->assertJsonMissingPath('recentMessages.0.email_id')->assertJsonMissingPath('recentMessages.0.body_text');

        $this->getJson('/admin/archive')->assertOk()->assertJsonPath('screen', 'archive');
        $this->actingAs($owner)->getJson('/admin')->assertOk()->assertJsonPath('screen', 'archive')->assertJsonMissingPath('metrics');
        foreach (['/admin/archive', '/admin/settings', '/admin/mailboxes'] as $path) {
            $this->get($path)->assertForbidden();
            $this->getJson($path)->assertForbidden();
        }
        $this->patchJson('/admin/settings/issuance', ['guest_issuance_enabled' => false])->assertForbidden();
        $this->putJson('/admin/settings/password', [])->assertForbidden();
        $this->postJson('/admin/mailboxes/'.$permanent->id.'/password')->assertForbidden();
    }

    public function test_pausing_issuance_preserves_active_guests_and_permanent_accounts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $guest = $this->getJson('/api/email')->assertOk()->json('data.id');
        $this->actingAs($admin)->getJson('/admin/settings')->assertOk()->assertJsonPath('guestIssuanceEnabled', true);
        $this->patchJson('/admin/settings/issuance', ['guest_issuance_enabled' => 0])->assertOk()->assertJsonPath('redirect', '/admin/settings');
        $this->getJson('/admin/settings')->assertJsonPath('guestIssuanceEnabled', false);
        $this->postJson('/admin/logout')->assertOk()->assertJsonStructure(['redirect']);
        $this->flushSession();
        $this->getJson('/api/email')->assertServiceUnavailable()->assertJsonPath('code', 'guest_issuance_paused');
        $this->withSession(['email_id' => $guest])->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $guest])->assertServiceUnavailable();
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $guest);
        $mailbox = Email::findOrFail($guest);
        $this->assertNull($mailbox->closed_at);
        app(MailDeliveryService::class)->deliver($mailbox->email, "From: sender@example.test\r\nSubject: Still receiving\r\n\r\nHello");
        $this->getJson('/api/messages')->assertJsonPath('data.0.subject', 'Still receiving');
        $this->assertSame(1, Email::count());
        $this->actingAs($admin)->postJson('/admin/mailboxes', ['local_part' => 'team', 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonStructure(['redirect']);
        $owner = User::whereEmail('team@'.config('mailbox.domain'))->firstOrFail();
        $this->useBrowserSession(Str::random(40));
        $this->actingAs($owner)->getJson('/api/email')->assertOk()->assertJsonPath('data.email', $owner->email);
        $this->useBrowserSession(Str::random(40));
        $this->actingAs($admin)->patchJson('/admin/settings/issuance', ['guest_issuance_enabled' => 1])->assertOk()->assertJsonPath('redirect', '/admin/settings');
        $this->postJson('/admin/logout');
        $this->getJson('/api/email')->assertOk();
    }

    public function test_passwords_can_be_changed_and_owner_credentials_reissued_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'initial-secret-123']);
        $owner = User::factory()->create(['password' => 'old-owner-secret']);
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        $cookie = config('session.cookie');
        $ownerLogin = $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'old-owner-secret'])->assertOk()->assertJsonStructure(['redirect']);
        $ownerSession = $ownerLogin->getCookie($cookie)->getValue();
        $this->useBrowserSession(Str::random(40));
        $login = $this->postJson('/admin/login', ['email' => $admin->email, 'password' => 'initial-secret-123'])->assertOk()->assertJsonStructure(['redirect']);
        $this->useBrowserSession($login->getCookie($cookie)->getValue());
        $this->putJson('/admin/settings/password', [
            'current_password' => 'incorrect', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $changed = $this->putJson('/admin/settings/password', [
            'current_password' => 'initial-secret-123', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123',
        ])->assertOk()->assertJsonPath('redirect', '/admin/settings');
        $firstSession = $changed->getCookie($cookie)->getValue();
        $this->useBrowserSession($firstSession);
        $this->assertTrue(Hash::check('new-secret-123', $admin->fresh()->password));
        $receipt = $this->postJson('/admin/mailboxes/'.$box->id.'/password')->assertOk()->json('redirect');
        $this->useBrowserSession($ownerSession);
        $this->getJson('/api/messages')->assertUnauthorized();
        $this->useBrowserSession(Str::random(40));
        $otherAdmin = User::factory()->create(['is_admin' => true, 'password' => 'other-admin-password']);
        $otherLogin = $this->postJson('/admin/login', ['email' => $otherAdmin->email, 'password' => 'other-admin-password'])->assertOk()->assertJsonStructure(['redirect']);
        $secondSession = $otherLogin->getCookie($cookie)->getValue();
        $this->useBrowserSession($secondSession);
        $newReceipt = $this->postJson('/admin/mailboxes/'.$box->id.'/password')
            ->assertOk()->json('redirect');
        $this->useBrowserSession($firstSession);
        $this->getJson($receipt)->assertJsonPath('credentials', null);
        $this->getJson($newReceipt)->assertJsonPath('credentials', null);
        $this->useBrowserSession($secondSession);
        $response = $this->getJson($newReceipt)->assertOk()->assertJsonPath('screen', 'mailboxes');
        $password = $response->json('credentials.password');
        $this->assertTrue(Hash::check($password, $owner->fresh()->password));
        $this->assertFalse(Hash::check('old-owner-secret', $owner->fresh()->password));
        $response->assertHeaderContains('Cache-Control', 'no-store');
        $this->getJson($newReceipt)->assertJsonPath('credentials', null);
        $guest = Email::create(['email' => 'temporary@example.test', 'expires_at' => now()->addDay()]);
        $this->postJson('/admin/mailboxes/'.$guest->id.'/password')->assertNotFound();
    }

    public function test_password_change_keeps_only_the_current_admin_authenticated_across_panel_and_api(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'starting-password']);
        $credentials = ['email' => $admin->email, 'password' => 'starting-password'];
        $cookie = config('session.cookie');
        $this->useBrowserSession(Str::random(40));
        $old = $this->postJson('/admin/login', $credentials)->assertOk()->assertJsonStructure(['redirect'])->getCookie($cookie)->getValue();
        $this->useBrowserSession(Str::random(40));
        $current = $this->postJson('/admin/login', $credentials)->assertOk()->assertJsonStructure(['redirect'])->getCookie($cookie)->getValue();
        $this->useBrowserSession($current);
        $current = $this->putJson('/admin/settings/password', [
            'current_password' => 'starting-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertOk()->assertJsonPath('redirect', '/admin/settings')->getCookie($cookie)->getValue();
        $this->useBrowserSession($current);
        $this->get('/admin/settings')->assertOk();
        $this->useBrowserSession($old);
        $this->getJson('/api/messages')->assertUnauthorized();
        $this->get('/admin/settings')->assertRedirect('/admin/login');
    }

    private function useBrowserSession(string $id): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->withCredentials()->withCookie(config('session.cookie'), $id);
    }
}
