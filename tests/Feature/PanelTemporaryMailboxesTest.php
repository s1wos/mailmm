<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PanelTemporaryMailboxesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_temporary_mailboxes_page_or_read_its_data(): void
    {
        $this->withoutVite();
        $url = '/admin/temporary-mailboxes';
        $this->get($url)->assertRedirect('/admin/login');
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->getJson($url)->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get($url)
            ->assertOk()->assertSee('id="app"', false);
        $this->getJson($url)->assertOk()->assertJsonPath('screen', 'temporary-mailboxes')
            ->assertJsonPath('mailboxes.data', [])->assertJsonPath('nextExpiryAt', null)
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_the_list_paginates_active_guest_addresses_and_counts_their_visible_letters(): void
    {
        $this->travelTo('2026-11-22 10:00:00.123456 UTC');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $owner = User::factory()->create();
        $owner->mailbox()->create(['email' => $owner->email]);
        Email::create(['email' => 'unassigned@example.test']);
        Email::create(['email' => 'expired@example.test', 'expires_at' => now()]);
        Email::create(['email' => 'closed@example.test', 'expires_at' => now()->addDay(), 'closed_at' => now()]);

        foreach (range(1, 12) as $number) {
            Email::create(['email' => "guest{$number}@example.test", 'expires_at' => now()->addDay()]);
        }
        $mailbox = Email::create(['email' => 'latest@example.test', 'expires_at' => now()->addMicrosecond()]);
        $fields = ['from' => 'sender@example.test', 'recipient' => $mailbox->email];
        $mailbox->messages()->create($fields + ['received_at' => now()]);
        $mailbox->messages()->create($fields + ['received_at' => now(), 'is_pending' => true]);
        $mailbox->messages()->create($fields + ['received_at' => now()->subDays(365)]);

        $first = $this->getJson('/admin/temporary-mailboxes')->assertOk()
            ->assertJsonPath('serverTime', '2026-11-22T10:00:00.123456Z')
            ->assertJsonPath('nextExpiryAt', '2026-11-22T10:00:00.123457Z')
            ->assertJsonPath('mailboxes.total', 13)->assertJsonCount(12, 'mailboxes.data');
        $this->assertEquals([
            'id' => $mailbox->id, 'email' => $mailbox->email,
            'created_at' => '2026-11-22T10:00:00.123456Z', 'expires_at' => '2026-11-22T10:00:00.123457Z',
            'messages_count' => 1,
        ], $first->json('mailboxes.data.0'));
        $first->assertJsonPath('mailboxes.next_page_url', '/admin/temporary-mailboxes?page=2');
        $this->getJson($first->json('mailboxes.next_page_url'))->assertOk()
            ->assertJsonCount(1, 'mailboxes.data')->assertJsonPath('mailboxes.data.0.email', 'guest1@example.test')
            ->assertJsonPath('nextExpiryAt', '2026-11-22T10:00:00.123457Z')
            ->assertJsonPath('mailboxes.data.0.messages_count', 0);
    }

    public function test_counting_messages_uses_one_query_for_a_full_or_partial_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (range(1, 13) as $number) {
            Email::create(['email' => "guest{$number}@example.test", 'expires_at' => now()->addDay()]);
        }

        $connection = DB::connection();
        $connection->enableQueryLog();
        try {
            foreach ([1 => 12, 2 => 1] as $page => $mailboxes) {
                $connection->flushQueryLog();
                $this->getJson("/admin/temporary-mailboxes?page={$page}")->assertOk()
                    ->assertJsonCount($mailboxes, 'mailboxes.data');
                $queries = collect($connection->getQueryLog())
                    ->filter(fn ($query) => str_contains($query['query'], '"messages"'));
                $this->assertCount(1, $queries);
            }
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }
}
