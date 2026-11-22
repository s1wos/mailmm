<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\MailboxDomain;
use App\Models\User;
use App\Services\MailboxSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailboxActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_keeps_the_stale_confirmation_and_limits_the_next_confirmed_change(): void
    {
        $this->freezeTime();
        $issued = $this->getJson('/api/email')->assertOk()->assertHeader('X-Mailmm-Is-Admin', '0');
        $this->withCredentials()->withCookie(config('session.cookie'), $issued->getCookie(config('session.cookie'))->getValue());
        $first = ['X-Expected-Mailbox-Id' => $issued->json('data.id')];
        $next = ['X-Expected-Mailbox-Id' => $this->postJson('/api/emails', headers: $first)->assertCreated()->json('data.id')];
        $this->postJson('/api/emails', headers: $first)->assertConflict();
        $this->postJson('/api/emails', headers: $next)->assertTooManyRequests()->assertHeader('Retry-After', '1')
            ->assertJsonPath('code', 'action_rate_limited');
        $this->getJson('/api/email')->assertOk()->assertJsonPath('data.id', $next['X-Expected-Mailbox-Id']);
        $this->travel(1)->second();
        $this->postJson('/api/emails', headers: $next)->assertCreated();
    }

    public function test_admin_actions_bypass_both_rate_limits_without_charging_the_guest_ip(): void
    {
        $key = MailboxSession::ISSUE_LIMIT_PREFIX.hash('sha256', '127.0.0.1');
        RateLimiter::increment($key, 3600, 10);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/')->assertSee('name="mailmm-is-admin" content="1"', false);
        for ($i = 0; $i < 12; $i++) {
            $box = $this->getJson('/api/email')->assertOk()->assertHeader('X-Mailmm-Is-Admin', '1')->json('data.id');
            $new = $this->postJson('/api/emails', headers: ['X-Expected-Mailbox-Id' => $box])->assertCreated()->json('data.id');
            $this->deleteJson('/api/email', headers: ['X-Expected-Mailbox-Id' => $new])->assertNoContent();
        }
        $this->assertSame(10, RateLimiter::attempts($key));
    }

    public function test_an_owner_can_read_a_different_letter_immediately_but_must_wait_to_repeat_the_same_one(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $box = Email::create(['email' => $owner->email, 'owner_user_id' => $owner->id]);
        foreach (['one', 'two'] as $subject) {
            $ids[] = $box->messages()->create(['recipient' => $box->email, 'subject' => $subject,
                'from' => 'sender@test.invalid', 'received_at' => now()])->id;
        }
        $this->actingAs($owner)->getJson('/api/email')->assertHeader('X-Mailmm-Is-Admin', '0');
        $this->getJson('/api/messages/'.$ids[0])->assertOk();
        $this->getJson('/api/messages/'.$ids[1])->assertOk();
        $this->getJson('/api/messages/'.$ids[0])->assertTooManyRequests()->assertHeader('Retry-After', '1');
        $this->travel(1)->second();
        $this->getJson('/api/messages/'.$ids[0])->assertOk();
    }

    public function test_resuming_an_attachment_returns_the_requested_bytes_during_the_download_cooldown(): void
    {
        Storage::fake('local')->put('letter/document', 'abcdefghij');
        $issued = $this->getJson('/api/email')->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), $issued->getCookie(config('session.cookie'))->getValue());
        $box = Email::findOrFail($issued->json('data.id'));
        $letter = $box->messages()->create(['recipient' => $box->email, 'from' => 'a@test.invalid', 'subject' => 'Document',
            'received_at' => now(), 'attachments' => [['path' => 'letter/document', 'filename' => 'doc.txt']]]);
        $path = '/api/messages/'.$letter->id.'/attachments/0';
        $this->get($path)->assertDownload('doc.txt')->assertHeader('Accept-Ranges', 'bytes');
        $this->get($path)->assertTooManyRequests();
        $partial = $this->get($path, ['Range' => 'bytes=3-6'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 3-6/10')->assertHeader('Content-Length', '4');
        ob_start();
        $partial->baseResponse->sendContent();
        $this->assertSame('defg', ob_get_clean());
    }

    public function test_a_withdrawn_domain_returns_current_options_with_the_failed_rotation(): void
    {
        $box = $this->getJson('/api/email')->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), $box->getCookie(config('session.cookie'))->getValue());
        $domain = MailboxDomain::sole();
        $this->getJson('/api/email/options')->assertOk();
        $domain->update(['issuance_enabled' => false]);
        MailboxDomain::create(['name' => 'other.test', 'issuance_enabled' => true]);
        $this->postJson('/api/emails', ['domain' => $domain->name], ['X-Expected-Mailbox-Id' => $box->json('data.id')])
            ->assertUnprocessable()->assertJsonValidationErrors('domain')->assertJsonPath('options.0.domain', 'other.test');
    }

    public function test_direct_page_reads_have_a_ceiling_even_without_the_interface_header(): void
    {
        $this->freezeTime();
        $this->get('/')->assertSee('name="mailmm-is-admin" content="0"', false);
        $owner = User::factory()->create();
        $owner->mailbox()->create(['email' => $owner->email]);
        $this->actingAs($owner)->get('/')->assertSee('name="mailmm-is-admin" content="0"', false);
        $cursor = (new \Illuminate\Pagination\Cursor(['id' => 1, 'received_at' => now()->toDateTimeString()]))->encode();
        $paths = ['/admin', '/api/messages?cursor='.$cursor];
        foreach ($paths as $path) {
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $this->getJson($path)->assertOk();
            }
            $this->getJson($path, ['X-Mailmm-Is-Admin' => '1'])->assertTooManyRequests()->assertHeader('Retry-After', '1');
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ($paths as $path) {
            for ($attempt = 0; $attempt < 7; $attempt++) {
                $this->getJson($path)->assertOk();
            }
        }
    }
}
