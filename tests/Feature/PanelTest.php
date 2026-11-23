<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\MailboxDomain;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_panel_negotiates_the_document_and_private_session_data_and_resumes_a_direct_link(): void
    {
        $archive = '/admin/archive?page=2&recipient=ops%40example.test';
        $this->getJson('/admin')->assertUnauthorized()->assertHeaderContains('Cache-Control', 'no-store');
        $login = $this->get($archive)->assertRedirect('/admin/login');
        $cookie = config('session.cookie');
        $this->withCredentials()->withCookie($cookie, $login->getCookie($cookie)->getValue());
        $this->get('/admin/login')->assertOk()->assertHeaderContains('Vary', 'Accept')->assertSee('id="app"', false);
        $this->getJson('/admin/login')->assertExactJson(['screen' => 'login', 'user' => null]);
        $this->getJson('/admin/session')->assertExactJson(['user' => null])->assertHeaderContains('Cache-Control', 'no-store');

        $admin = User::factory()->create(['is_admin' => true]);
        $login = $this->postJson('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', $archive);
        $this->withCookie($cookie, $login->getCookie($cookie)->getValue());
        $identity = ['id' => $admin->id, 'email' => $admin->email, 'is_admin' => true];
        $this->getJson('/admin/session')->assertExactJson(['user' => $identity])
            ->assertHeaderContains('Cache-Control', 'private');
        $this->getJson('/admin/login')->assertExactJson(['redirect' => '/admin']);
        $this->get('/admin/login')->assertRedirect('/admin');
        $this->get($archive)->assertOk()->assertSee('id="app"', false)->assertDontSee($admin->email);
        $this->getJson($archive)->assertJsonPath('user', $identity)->assertJsonPath('screen', 'archive')
            ->assertJsonPath('recipient', 'ops@example.test')->assertHeaderContains('Vary', 'Accept');
    }

    public function test_only_admins_manage_domains_and_additions_start_as_receive_only(): void
    {
        $url = '/admin/settings/domains';
        $this->postJson($url, ['name' => 'b.test'])->assertUnauthorized();
        $this->patchJson($url.'/1', ['issuance_enabled' => true])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['name' => 'b.test'])->assertForbidden();
        $this->patchJson($url.'/1', ['issuance_enabled' => true])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patchJson('/admin/settings/issuance', ['guest_issuance_enabled' => false])->assertOk()->assertJsonStructure(['redirect']);
        $existing = MailboxDomain::sole();
        $existing->update(['name' => 'z.test']);
        $added = $this->postJson($url, ['name' => ' B.TEST. ', 'issuance_enabled' => true])
            ->assertOk()->assertJsonPath('redirect', '/admin/settings')->assertJsonStructure(['message']);
        $domain = MailboxDomain::where('name', 'b.test')->sole();
        $this->assertFalse($domain->issuance_enabled);
        $domains = [
            ['id' => $domain->id, 'name' => 'b.test', 'issuance_enabled' => false],
            ['id' => $existing->id, 'name' => 'z.test', 'issuance_enabled' => true],
        ];
        $added->assertJsonPath('domains', $domains);
        $this->getJson('/admin/settings')->assertOk()->assertJsonPath('domains', $domains);
        $this->postJson($url, ['name' => 'b.test'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($url, ['name' => 'foo..test'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($url.'/'.$domain->id, ['issuance_enabled' => 'oops'])->assertUnprocessable()->assertJsonValidationErrors('issuance_enabled');
        $this->getJson('/api/email/options')->assertOk()->assertJsonMissing(['domain' => 'b.test']);
        $domains[0]['issuance_enabled'] = true;
        $this->patchJson($url.'/'.$domain->id, ['issuance_enabled' => 1, 'name' => 'renamed.test'])
            ->assertOk()->assertJsonPath('redirect', '/admin/settings')->assertJsonPath('domains', $domains)->assertJsonStructure(['message']);
        $this->getJson('/api/email/options')->assertOk()->assertJsonFragment(['domain' => 'b.test']);
        $domains[0]['issuance_enabled'] = false;
        $this->patchJson($url.'/'.$domain->id, ['issuance_enabled' => 0])
            ->assertOk()->assertJsonPath('redirect', '/admin/settings')->assertJsonPath('domains', $domains);
        $this->getJson('/api/email/options')->assertOk()->assertJsonMissing(['domain' => 'b.test']);
        $this->post($url, ['name' => 'c.test'])->assertRedirect('/admin/settings')->assertSessionHas('status');
        $this->patch($url.'/'.$domain->id, ['issuance_enabled' => 1])->assertRedirect('/admin/settings')->assertSessionHas('status');
    }

    public function test_panel_read_validation_applies_only_to_the_requested_screen_data(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['/admin/archive?recipient[]=invalid', '/admin?recipient[]=invalid'] as $archive) {
            $this->get($archive)->assertOk()->assertSee('id="app"', false);
            $this->getJson($archive)->assertUnprocessable()->assertJsonValidationErrors('recipient');
        }
        foreach (['/admin/mailboxes?issued=bad', '/admin?issued=bad'] as $mailboxes) {
            $this->get($mailboxes)->assertOk()->assertSee('id="app"', false);
            $this->getJson($mailboxes)->assertUnprocessable()->assertJsonValidationErrors('issued');
        }

        $this->getJson('/admin?recipient=ops&issued=bad')->assertOk()->assertJsonPath('screen', 'archive');
        $this->getJson('/admin/mailboxes?recipient[]=invalid&issued=1&ticket[]=invalid')
            ->assertOk()->assertJsonPath('credentials', null);
        $this->actingAs(User::factory()->create())->getJson('/admin?issued=bad')
            ->assertOk()->assertJsonPath('screen', 'archive');
    }

    public function test_a_stale_panel_cannot_change_settings_or_log_out_the_new_account(): void
    {
        $previous = User::factory()->create(['is_admin' => true]);
        $current = User::factory()->create(['is_admin' => true]);
        $domain = MailboxDomain::create(['name' => 'stale.test']);
        $url = '/admin/settings/domains/'.$domain->id;
        $data = ['issuance_enabled' => true];
        $stale = ['X-Panel-User' => (string) $previous->id];
        $fresh = ['X-Panel-User' => (string) $current->id];

        $this->patchJson($url, $data, $stale)->assertUnauthorized();
        $this->actingAs($current)->patchJson($url, $data, $stale)
            ->assertConflict()->assertExactJson(['code' => 'panel_user_changed']);
        $this->assertFalse($domain->fresh()->issuance_enabled);
        $this->postJson('/admin/logout', [], $stale)->assertConflict()->assertExactJson(['code' => 'panel_user_changed']);
        $this->getJson('/admin/session')->assertJsonPath('user.id', $current->id);

        $this->patchJson($url, $data, $fresh)->assertOk();
        $this->assertTrue($domain->fresh()->issuance_enabled);
        $this->postJson('/admin/logout', [], $fresh)->assertOk();
        $this->assertGuest();
    }

    public function test_permanent_addresses_use_the_chosen_domain_and_format(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $first = MailboxDomain::sole();
        $first->update(['name' => 'a.test']);
        $second = MailboxDomain::create(['name' => 'b.test']);
        $this->getJson('/admin/mailboxes')->assertJsonPath('domains.0.domain', 'a.test')
            ->assertJsonPath('domains.1.domain', 'b.test')->assertJsonPath('domains.1.formats', ['random', 'words']);
        foreach ([$first, $second] as $domain) {
            $receipt = $this->issueMailbox(['domain' => $domain->name, 'local_part' => 'mint.fox'])
                ->assertOk()->json('redirect');
            $this->getJson($receipt)->assertJsonPath('credentials.email', 'mint.fox@'.$domain->name);
        }
        $receipt = $this->issueMailbox(['domain' => 'b.test', 'format' => 'words'])
            ->assertOk()->json('redirect');
        $address = $this->getJson($receipt)->json('credentials.email');
        $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+@b\.test$/', $address);
        $this->issueMailbox(['domain' => 'b.test', 'local_part' => 'mint.fox'])->assertUnprocessable()->assertJsonValidationErrors('local_part');
        Email::create(['email' => 'taken@a.test', 'closed_at' => now()]);
        $this->issueMailbox(['domain' => 'a.test', 'local_part' => 'taken'])->assertUnprocessable()->assertJsonValidationErrors('local_part');
        $this->assertDatabaseMissing('users', ['email' => 'taken@a.test']);
        $this->issueMailbox(['domain' => 'missing.test', 'format' => 'words'])
            ->assertUnprocessable()->assertJsonValidationErrors('domain')
            ->assertJsonCount(2, 'domains')->assertJsonPath('domains.1.domain', 'b.test')
            ->assertJsonPath('domains.1.limit', 64);
        $this->getJson('/api/email/options')->assertJsonMissing(['domain' => 'b.test']);
        $this->assertDatabaseCount('users', 4);
    }

    public function test_admin_can_create_a_permanent_mailbox_when_all_domains_are_receive_only(): void
    {
        $domain = MailboxDomain::sole();
        $domain->update(['issuance_enabled' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $receipt = $this->issueMailbox(['local_part' => 'team'])->assertOk()->json('redirect');
        $this->getJson($receipt)->assertJsonPath('credentials.email', 'team@'.$domain->name)
            ->assertJsonPath('domains.0.domain', $domain->name);
        $this->assertDatabaseHas('emails', ['email' => 'team@'.$domain->name, 'expires_at' => null]);
        $this->getJson('/api/email/options')->assertExactJson(['data' => []]);
        $this->postJson('/api/emails', ['domain' => $domain->name])->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    private function issueMailbox(array $data = []): TestResponse
    {
        return $this->postJson('/admin/mailboxes', $data + ['request_id' => (string) Str::uuid()]);
    }

    public function test_retrying_an_issuance_returns_its_original_single_use_ticket(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $this->enableOnly('a.test');
        $intent = $this->getJson('/admin/mailboxes')->assertOk()->json('request_id');
        $this->assertTrue(Str::isUuid($intent));
        $this->postJson('/admin/mailboxes')->assertUnprocessable()->assertJsonValidationErrors('request_id')->assertJsonMissingPath('domains');

        $data = ['domain' => 'a.test', 'request_id' => $intent];
        $receipt = $this->issueMailbox($data)->assertOk()->json('redirect');
        $this->enableOnly('b.test');
        MailboxDomain::where('name', 'a.test')->delete();
        $this->issueMailbox($data)->assertOk()->assertJsonPath('redirect', $receipt);
        $this->issueMailbox(['domain' => 'missing.test', 'request_id' => $intent])
            ->assertUnprocessable()->assertJsonValidationErrors('request_id')->assertJsonPath('domains.0.domain', 'b.test');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('emails', 1);

        $session = $this->app['session'];
        $originalToken = $session->token();
        $session->regenerateToken();
        $this->getJson($receipt)->assertJsonPath('credentials', null);
        $session->put('_token', $originalToken);
        $credentials = $this->getJson($receipt)->assertOk()->json('credentials');
        $this->assertTrue(Hash::check($credentials['password'], User::whereEmail($credentials['email'])->sole()->password));
        $this->issueMailbox($data)->assertOk()->assertJsonPath('redirect', $receipt);
        $this->getJson($receipt)->assertOk()->assertJsonPath('credentials', null);
        $this->travel(11)->minutes();
        $this->issueMailbox($data)->assertOk()->assertJsonPath('redirect', $receipt);

        $this->issueMailbox(['domain' => 'b.test'])->assertOk();
        $this->assertDatabaseCount('emails', 2);
        $this->assertDatabaseCount('users', 3);
    }

    private function enableOnly(string $name): MailboxDomain
    {
        MailboxDomain::query()->update(['issuance_enabled' => false]);

        return MailboxDomain::updateOrCreate(['name' => $name], ['issuance_enabled' => true]);
    }

    public function test_reset_ticket_survives_a_creation_retry_and_is_bound_to_its_session(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->enableOnly('a.test');
        $data = ['request_id' => (string) Str::uuid(), 'local_part' => 'team'];
        $creation = $this->issueMailbox($data)->assertOk()->json('redirect');
        $box = Email::whereEmail('team@a.test')->sole();
        $reset = $this->postJson("/admin/mailboxes/{$box->id}/password")->assertOk()->json('redirect');
        parse_str(parse_url($reset, PHP_URL_QUERY), $query);
        $this->assertTrue(Str::isUuid($query['ticket']));
        $this->issueMailbox($data)->assertOk()->assertJsonPath('redirect', $creation);
        $this->getJson($creation)->assertOk()->assertJsonPath('credentials', null);
        $session = $this->app['session'];
        $token = $session->token();
        $session->regenerateToken();
        $this->getJson($reset)->assertOk()->assertJsonPath('credentials', null);
        $session->put('_token', $token);
        $password = $this->getJson($reset)->assertOk()->json('credentials.password');
        $this->assertTrue(Hash::check($password, User::whereEmail($box->email)->sole()->password));
        $this->getJson($reset)->assertOk()->assertJsonPath('credentials', null);
        $this->assertDatabaseCount('emails', 1);
    }

    public function test_failure_to_store_reset_ticket_rolls_back_the_password_change(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $owner = User::factory()->create(['password' => 'previous-password']);
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_starts_with($sql, 'insert into "mailbox_issuances"')) {
                throw new \RuntimeException('Receipt unavailable');
            }
        });
        $this->postJson("/admin/mailboxes/{$box->id}/password")->assertServerError();
        $this->assertSame($owner->password, $owner->fresh()->password);
        $this->assertSame($owner->getRememberToken(), $owner->fresh()->getRememberToken());
    }

    public function test_unexpired_session_cache_reset_ticket_issued_before_upgrade_is_consumed_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $owner = User::factory()->create(['password' => 'legacy-password']);
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        $ticket = Str::random(24);
        $this->app['session']->cache()->put("issued-{$admin->id}-{$box->id}", Crypt::encrypt([
            'email' => $owner->email, 'password' => 'legacy-password', 'ticket' => $ticket,
        ]), now()->addMinutes(10));
        $path = "/admin/mailboxes?issued={$box->id}&ticket=";
        $this->getJson($path.Str::random(24))->assertJsonPath('credentials', null);
        $this->getJson($path.$ticket)->assertJsonPath('credentials.password', 'legacy-password');
        $this->getJson($path.$ticket)->assertJsonPath('credentials', null);
    }

    public function test_only_active_permanent_mailboxes_are_listed_and_offer_a_password_reset(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $permanent = User::factory()->create()->mailbox()->create(['email' => 'permanent@a.test']);
        $expires = User::factory()->create()->mailbox()->create(['email' => 'expires@a.test', 'expires_at' => now()->addDay()]);
        $this->getJson('/admin/mailboxes')->assertOk()->assertJsonPath('mailboxes.total', 1)
            ->assertJsonPath('mailboxes.data.0.id', $permanent->id);
        $this->getJson('/admin')->assertOk()->assertJsonPath('metrics.permanent', 1);
        $this->postJson("/admin/mailboxes/{$expires->id}/password")->assertNotFound();
    }

    public function test_only_an_administrator_can_issue_permanent_mailbox_credentials(): void
    {
        $this->enableOnly('post.example.test');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->issueMailbox(['local_part' => 'team'])->assertUnauthorized();
        $this->get('/register')->assertNotFound();
        $admin = User::factory()->create(['is_admin' => true]);
        $receipt = $this->actingAs($admin)->issueMailbox(['local_part' => 'team'])->assertOk()->json('redirect');
        $address = 'team@post.example.test';
        $owner = User::whereEmail($address)->firstOrFail();
        $this->assertFalse($owner->is_admin);
        $this->assertDatabaseHas('emails', ['email' => $address, 'expires_at' => null, 'owner_user_id' => $owner->id]);
        $this->getJson('/api/email')->assertOk();
        $issued = $this->getJson($receipt)->assertOk()->assertJsonPath('credentials.email', $address);
        $password = $issued->json('credentials.password');
        $issued->assertHeaderContains('Cache-Control', 'no-store');
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->getJson($receipt)->assertJsonPath('credentials', null);
        $this->issueMailbox(['local_part' => 'team'])->assertUnprocessable()->assertJsonValidationErrors('local_part');
        $this->postJson('/admin/logout')->assertOk()->assertJsonPath('redirect', '/admin/login');
        $this->postJson('/admin/login', ['email' => $address, 'password' => $password])->assertOk()->assertJsonPath('redirect', '/admin');
        $this->assertAuthenticatedAs($owner);
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.email', $address)->assertJsonPath('data.expires_at', null);
        $this->postJson('/api/emails')->assertForbidden();
        $this->deleteJson('/api/email')->assertForbidden();
        $this->issueMailbox(['local_part' => 'other'])->assertForbidden();
        $this->postJson('/admin/logout')->assertOk()->assertJsonPath('redirect', '/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_archive_search_and_direct_links_respect_account_and_message_age(): void
    {
        $this->travelTo(now()->startOfSecond());
        Storage::fake('local');
        Storage::disk('local')->put('mail/file', 'archive');
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $box = Email::create(['email' => $owner->email, 'owner_user_id' => $owner->id]);
        $mine = $box->messages()->create(['recipient' => $box->email, 'from' => 'one@test.invalid',
            'subject' => 'My inbox', 'received_at' => now()]);
        $unknown = Message::create(['recipient' => 'unlisted@test.invalid', 'from' => 'two@test.invalid',
            'subject' => 'Archived letter', 'received_at' => now()->subDays(365)->addSecond(),
            'attachments' => [['path' => 'mail/file', 'filename' => 'doc.txt', 'mime' => 'text/plain', 'size' => 7]]]);
        $url = "/admin/messages/{$unknown->id}";
        $this->actingAs($owner)->getJson('/admin')->assertJsonPath('messages.data.0.subject', 'My inbox')->assertJsonCount(1, 'messages.data')
            ->assertJsonPath('messages.data.0.expires_at', now()->addDays(365)->toISOString())
            ->assertJsonPath('serverTime', now()->toISOString());
        $this->getJson("/admin/messages/{$mine->id}")->assertOk()->assertJsonPath('letter.subject', 'My inbox');
        $this->get($url)->assertNotFound();
        $this->getJson($url)->assertNotFound()->assertHeaderContains('Cache-Control', 'no-store');
        $this->get($url.'/attachments/0')->assertNotFound();
        $this->actingAs($admin)->getJson('/admin')->assertJsonPath('recentMessages.0.subject', 'My inbox')->assertJsonPath('recentMessages.1.subject', 'Archived letter')
            ->assertJsonPath('recentMessages.1.expires_at', now()->addSecond()->toISOString())
            ->assertJsonPath('serverTime', now()->toISOString());
        $this->getJson('/admin?recipient=unlisted%40test.invalid')->assertJsonPath('screen', 'archive')
            ->assertJsonPath('messages.data.0.subject', 'Archived letter')->assertJsonCount(1, 'messages.data');
        $this->getJson($url)->assertJsonPath('letter.subject', 'Archived letter')
            ->assertJsonPath('letter.expires_at', now()->addSecond()->toISOString())
            ->assertJsonPath('serverTime', now()->toISOString())
            ->assertJsonPath('letter.attachments', [['filename' => 'doc.txt', 'url' => $url.'/attachments/0']])
            ->assertJsonMissingPath('letter.email_id')->assertJsonMissingPath('letter.delivery_key');
        $this->get($url.'/attachments/0')->assertDownload('doc.txt');
        $this->travel(1)->second();
        $this->getJson('/admin')->assertJsonMissing(['subject' => 'Archived letter']);
        $this->get($url)->assertNotFound();
        $this->get($url.'/attachments/0')->assertNotFound();
    }

    public function test_each_permanent_owner_can_list_read_and_download_only_their_own_mail(): void
    {
        Storage::fake('local');
        $letters = [];
        foreach (['first', 'second'] as $name) {
            $owner = User::factory()->create();
            $box = $owner->mailbox()->create(['email' => $owner->email]);
            $path = "mail/{$name}.txt";
            Storage::disk('local')->put($path, $name);
            $letter = $box->messages()->create([
                'from' => 'sender@example.test', 'recipient' => $box->email, 'subject' => $name, 'received_at' => now(),
                'attachments' => [['path' => $path, 'filename' => "{$name}.txt", 'size' => strlen($name), 'mime' => 'text/plain']],
            ]);
            $letters[] = [$owner, $letter];
        }

        foreach ($letters as $index => [$owner, $own]) {
            $this->actingAs($owner)->getJson('/admin')->assertOk()->assertJsonCount(1, 'messages.data')
                ->assertJsonPath('messages.data.0.id', $own->id);
            $url = "/admin/messages/{$own->id}";
            $this->getJson($url)->assertOk()->assertJsonPath('letter.subject', $own->subject);
            $this->get($url.'/attachments/0')->assertDownload($own->subject.'.txt')->assertStreamedContent($own->subject);

            $other = $letters[1 - $index][1];
            $this->get("/admin/messages/{$other->id}")->assertNotFound();
            $this->getJson("/admin/messages/{$other->id}")->assertNotFound();
            $this->get("/admin/messages/{$other->id}/attachments/0")->assertNotFound();
        }
    }

    public function test_archive_reports_the_first_expiry_across_all_matching_pages(): void
    {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->create();
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        $message = ['recipient' => $owner->email, 'from' => 'sender@example.test'];
        $oldest = now()->subDays(365)->addMinute();
        $laterExpiry = now()->addDays(365)->toISOString();
        $box->messages()->create($message + ['received_at' => $oldest]);
        for ($n = 0; $n < 25; $n++) {
            $box->messages()->create($message + ['received_at' => now()]);
        }
        Message::create(['recipient' => 'other@example.test', 'from' => 'other@example.test', 'received_at' => $oldest->copy()->subSecond()]);
        $box->messages()->create($message + ['received_at' => $oldest->copy()->subSeconds(2), 'is_pending' => true]);
        $filtered = route('panel.archive', ['recipient' => $owner->email], false);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson($filtered)
            ->assertJsonCount(25, 'messages.data')->assertJsonPath('messages.total', 26)
            ->assertJsonPath('nextExpiryAt', now()->addMinute()->toISOString());
        $this->actingAs($owner)->getJson('/admin')->assertJsonPath('nextExpiryAt', now()->addMinute()->toISOString());
        $this->travel(1)->minute();
        $this->getJson('/admin')->assertJsonPath('messages.total', 25)->assertJsonPath('messages.next_page_url', null)
            ->assertJsonPath('nextExpiryAt', $laterExpiry);
    }

    public function test_bootstrap_command_creates_an_admin_and_cannot_promote_an_existing_account(): void
    {
        $this->artisan('mail:admin', ['email' => 'operator@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->expectsQuestion('Confirm password', 'correct horse battery')
            ->assertSuccessful();
        $this->assertTrue(User::whereEmail('operator@example.test')->firstOrFail()->is_admin);
        $owner = User::factory()->create();
        $this->artisan('mail:admin', ['email' => $owner->email])->assertFailed();
        $this->assertFalse($owner->fresh()->is_admin);
    }

    public function test_message_links_return_to_the_original_dashboard_or_filtered_list_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $box = $owner->mailbox()->create(['email' => $owner->email]);
        $letters = [];
        for ($n = 0; $n < 26; $n++) {
            $letters[] = $box->messages()->create(['recipient' => $owner->email, 'from' => 'sender@example.test',
                'subject' => "Letter $n", 'received_at' => now()->subMinutes($n)]);
        }
        $overviewLetter = route('panel.message', ['message' => $letters[0]->id, 'from' => 'overview']);
        $this->actingAs($admin)->getJson('/admin')->assertJsonPath('recentMessages.0.id', $letters[0]->id);
        $this->getJson($overviewLetter)->assertJsonPath('backUrl', '/admin');

        $filter = ['recipient' => $owner->email, 'page' => 2];
        $filteredArchive = route('panel.archive', $filter, false);
        $olderLetter = route('panel.message', ['message' => $letters[25]->id, ...$filter]);
        $this->getJson($filteredArchive)->assertJsonPath('messages.data.0.id', $letters[25]->id)
            ->assertJsonPath('messages.total', 26)->assertJsonPath('messages.last_page', 2)
            ->assertJsonPath('messages.current_page', 2)->assertJsonPath('listQuery', $filter)
            ->assertJsonPath('messages.prev_page_url', route('panel.archive', ['recipient' => $owner->email, 'page' => 1], false))
            ->assertJsonPath('messages.next_page_url', null);
        $this->getJson($olderLetter)->assertJsonPath('backUrl', $filteredArchive);
        $this->getJson(route('panel.index', ['recipient' => $owner->email, 'ticket' => 'unused'], false))
            ->assertJsonPath('messages.next_page_url', $filteredArchive);
        $this->getJson('/admin?issued=1&ticket=unused&page=2')
            ->assertJsonPath('mailboxes.prev_page_url', '/admin/mailboxes?page=1')
            ->assertJsonPath('mailboxes.next_page_url', null);

        $ownerPage = route('panel.index', ['page' => 2], false);
        $ownerLetter = route('panel.message', ['message' => $letters[25]->id, 'page' => 2]);
        $this->actingAs($owner)->getJson($ownerPage)->assertJsonPath('messages.data.0.id', $letters[25]->id)
            ->assertJsonPath('listQuery', ['page' => 2])
            ->assertJsonPath('messages.prev_page_url', '/admin?page=1');
        $this->getJson($ownerLetter)->assertJsonPath('backUrl', $ownerPage);
    }

    public function test_admin_command_counts_characters_for_the_minimum_and_bytes_for_bcrypt(): void
    {
        $login = ['email' => 'operator@example.test'];
        foreach ([str_repeat('я', 6), str_repeat('я', 37)] as $invalid) {
            $this->artisan('mail:admin', $login)
                ->expectsQuestion('Password (at least 12 characters)', $invalid)
                ->assertFailed();
        }
        $valid = str_repeat('я', 12);
        $this->artisan('mail:admin', $login)
            ->expectsQuestion('Password (at least 12 characters)', $valid)
            ->expectsQuestion('Confirm password', $valid)
            ->assertSuccessful();
    }

    public function test_an_account_without_an_assigned_mailbox_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $this->postJson('/admin/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_permanent_and_automatic_addresses_respect_the_complete_address_length(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->useDeterministicRandomStrings();
        $this->enableOnly('post.example.test');
        $initial = $this->getJson('/api/email')->assertOk()->assertJson(fn ($json) => $json->where('data.email',
            fn (string $address) => preg_match('/^[a-z0-9]{14}@post\.example\.test$/', $address) === 1)->etc());
        $receipt = $this->actingAs($admin)->issueMailbox()->assertOk()->json('redirect');
        $automatic = $this->getJson($receipt)->assertOk()->json('credentials');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{14}@post\.example\.test$/', $automatic['email']);
        $domain = implode('.', [str_repeat('a', 60), str_repeat('b', 60), str_repeat('c', 60), str_repeat('d', 56)]);
        $this->assertSame(239, strlen($domain));
        $this->enableOnly($domain);
        $next = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $initial->json('data.id')])->assertCreated()->assertJson(fn ($json) => $json->where('data.email',
            fn (string $address) => strlen($address) === 254 && str_ends_with($address, '@'.$domain))->etc());

        $this->actingAs($admin)
            ->issueMailbox(['local_part' => str_repeat('a', 15)])->assertUnprocessable()->assertJsonValidationErrors('local_part');
        $receipt = $this->issueMailbox(['local_part' => str_repeat('a', 14)])->assertOk()->json('redirect');
        $this->getJson($receipt)->assertJsonPath('credentials.email', str_repeat('a', 14).'@'.$domain);

        $this->enableOnly($domain.'x');
        $this->getJson('/admin/mailboxes')->assertJsonPath('domains.0.limit', 13);
        $shortDomain = $domain.'x';
        $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $next->json('data.id')])->assertCreated()->assertJson(fn ($json) => $json->where('data.email',
            fn (string $address) => strlen($address) === 254 && str_ends_with($address, '@'.$shortDomain))->etc());
        $receipt = $this->issueMailbox()->assertOk()->json('redirect');
        $automatic = $this->getJson($receipt)->json('credentials');
        $this->assertSame(254, strlen($automatic['email']));
        $this->assertStringEndsWith('@'.$shortDomain, $automatic['email']);
        $this->issueMailbox(['local_part' => 'operations'])->assertOk()->assertJsonStructure(['redirect']);
        $this->assertDatabaseHas('emails', ['email' => 'operations@'.$domain.'x']);
        $this->postJson('/admin/logout')->assertOk()->assertJsonStructure(['redirect']);
        $this->postJson('/admin/login', $automatic)->assertOk()->assertJsonPath('redirect', '/admin');
        $this->assertAuthenticatedAs(User::whereEmail($automatic['email'])->firstOrFail());
    }

    public function test_an_exhausted_or_unusable_domain_does_not_issue_invalid_addresses_or_close_an_existing_mailbox(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->useDeterministicRandomStrings();
        $domain = implode('.', [str_repeat('q', 63), str_repeat('r', 63), str_repeat('s', 63), str_repeat('t', 60)]);
        $this->enableOnly($domain);
        $this->assertSame(252, strlen($domain));
        $this->actingAs($admin)->getJson('/admin/mailboxes')->assertJsonPath('domains.0.limit', 1);
        $issued = $this->getJson('/api/email')->assertOk();
        $previous = $issued->json('data.email');
        $confirmed = ['X-Expected-Mailbox-Id' => $issued->json('data.id')];
        $this->assertSame(254, strlen($previous));
        foreach (str_split('abcdefghijklmnopqrstuvwxyz0123456789') as $name) {
            Email::firstOrCreate(['email' => $name.'@'.$domain]);
        }
        $this->postJson('/api/emails', headers: $confirmed)->assertServiceUnavailable()->assertJsonPath('message', 'Не удалось подобрать свободный адрес.');
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.email', $previous);
        $this->issueMailbox()->assertUnprocessable()->assertJsonValidationErrors('local_part');
        $this->assertDatabaseCount('users', 1);

        MailboxDomain::query()->delete();
        $this->getJson('/admin/mailboxes')->assertJsonCount(0, 'domains');
        $this->issueMailbox()->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson('/api/emails', headers: $confirmed)->assertServiceUnavailable()->assertJsonPath('code', 'no_issuable_domains');
    }

    private function useDeterministicRandomStrings(): void
    {
        $sequence = 0;
        Str::createRandomStringsUsing(static function (int $length) use (&$sequence): string {
            $value = base_convert((string) ++$sequence, 10, 36);

            return substr(str_pad($value, $length, 'x'), 0, $length);
        });
        $this->beforeApplicationDestroyed(static fn () => Str::createRandomStringsNormally());
    }

    public function test_two_issuance_tabs_can_read_their_own_credentials_once_within_ten_minutes(): void
    {
        $this->enableOnly('post.example.test');
        $admin = User::factory()->create(['is_admin' => true]);
        $first = $this->actingAs($admin)->issueMailbox(['local_part' => 'first'])->assertOk()->json('redirect');
        $second = $this->issueMailbox(['local_part' => 'second'])->assertOk()->json('redirect');
        $shell = $this->get($second)->assertOk()->assertSee('id="app"', false);
        $this->head($second, ['Accept' => 'application/json'])->assertOk();
        $this->getJson($second.'-invalid')->assertJsonPath('credentials', null);
        $this->getJson('/admin/session')->assertOk()->assertJsonPath('user.id', $admin->id);
        $this->getJson('/admin/mailboxes')->assertJsonPath('credentials', null);
        $this->getJson('/api/email')->assertOk();
        $password = $this->getJson($second)->assertJsonPath('credentials.email', 'second@post.example.test')->json('credentials.password');
        $shell->assertDontSee($password);
        $this->getJson($second)->assertJsonPath('credentials', null);
        $this->getJson(str_replace('/admin/mailboxes?', '/admin?', $first))->assertJsonPath('credentials.email', 'first@post.example.test');
        $third = $this->issueMailbox(['local_part' => 'third'])->assertOk()->json('redirect');
        $this->travel(10)->minutes();
        $this->getJson($third)->assertJsonPath('credentials', null);
        $this->artisan('mail:prune')->assertSuccessful();
        $this->assertDatabaseHas('mailbox_issuances', [
            'email_id' => Email::whereEmail('third@post.example.test')->sole()->id, 'encrypted_password' => null,
        ]);
    }
}
