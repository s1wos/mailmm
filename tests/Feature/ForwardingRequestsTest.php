<?php

namespace Tests\Feature;

use App\Jobs\SendForwardingVerification;
use App\Models\MailboxDomain;
use App\Models\MailboxForwardingRule;
use App\Models\MailForward;
use App\Models\Message;
use App\Models\User;
use App\Services\ForwardingRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForwardingRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const RULE = '/admin/account/forwarding';

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->mailbox()->create(['email' => $owner->email]);

        return $owner;
    }

    private function letter(?User $owner = null, bool $original = true): Message
    {
        $letter = Message::create(['email_id' => $owner?->mailbox->id, 'recipient' => $owner?->email ?? 'unassigned@mx.test',
            'from' => 'writer@example.org', 'received_at' => now(), 'body_html' => '<b>legacy</b>']);
        if ($original) {
            $letter->forceFill(['raw_path' => 'mail/example.eml', 'raw_size' => 4, 'raw_sha256' => hash('sha256', 'mail')])->save();
        }

        return $letter;
    }

    public function test_legacy_archive_and_cross_mailbox_visibility_are_checked_before_spending_quota(): void
    {
        config(['forwarding.manual_enabled' => true, 'forwarding.limits.manual_per_user' => 1]);
        $owner = $this->owner();
        $mine = $this->letter($owner, original: false);
        $url = "/admin/messages/{$mine->id}";
        $data = ['to' => 'reader@example.org', 'request_id' => (string) Str::uuid()];
        $this->postJson($url.'/forwards', $data)->assertUnauthorized();
        $this->actingAs($this->owner())->postJson($url.'/forwards', $data)->assertNotFound();
        $admin = User::factory()->create(['is_admin' => true]);
        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user)->getJson($url)->assertJsonPath('letter.forwarding.available', true)
                ->assertJsonPath('letter.forwarding.content', 'archive');
            $id = $this->postJson($url.'/forwards', $data)->assertAccepted()->json('forward.id');
            $this->postJson($url.'/forwards', $data)->assertAccepted()->assertJsonPath('forward.id', $id);
        }
        $this->assertDatabaseCount('mail_forwards', 2);
        $this->assertDatabaseCount('jobs', 2);
        $catchall = $this->letter(original: false);
        $url = "/admin/messages/{$catchall->id}/forwards";
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->postJson($url, ['request_id' => (string) Str::uuid()] + $data)->assertTooManyRequests();
        $this->assertDatabaseCount('mail_forwards', 2);
        $this->assertDatabaseCount('jobs', 2);
        $this->actingAs($owner)->postJson($url, $data)->assertNotFound();
    }

    public function test_archive_content_and_attachments_count_toward_the_hourly_byte_limit(): void
    {
        config(['forwarding.manual_enabled' => true, 'forwarding.limits.bytes' => 512]);
        $mail = $this->letter(original: false);
        $mail->update(['body_text' => str_repeat('x', 200), 'attachments' => [
            ['filename' => 'file.bin', 'path' => "emails/{$mail->id}/file.bin", 'size' => 400, 'mime' => 'application/octet-stream'],
        ]]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = "/admin/messages/{$mail->id}/forwards";
        $payload = ['to' => 'reader@example.org', 'request_id' => (string) Str::uuid()];
        $this->postJson($url, $payload)->assertTooManyRequests()->assertJsonPath('code', 'quota_exceeded');
        $this->assertDatabaseCount('jobs', 0);
        config(['forwarding.limits.bytes' => 4096]);
        $this->postJson($url, $payload)->assertAccepted();
        $this->assertGreaterThanOrEqual(600, DB::table('forwarding_quota_buckets')->where('scope', 'bytes')->value('used'));
    }

    public function test_op_history_is_private_and_rollback_discards_jobs_and_quota(): void
    {
        config(['forwarding.manual_enabled' => true, 'forwarding.limits.manual_per_user' => 1]);
        $owner = $this->owner();
        $mine = $this->letter($owner);
        $this->actingAs($owner);
        $url = "/admin/messages/{$mine->id}";
        $data = ['request_id' => (string) Str::uuid(), 'to' => $owner->email];
        DB::beginTransaction();
        $this->postJson($url.'/forwards', $data)->assertAccepted();
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $id = $this->postJson($url.'/forwards', $data)->assertAccepted()->json('forward.id');
        $this->getJson($url)->assertJsonPath('forwards.0.id', $id)->assertJsonMissingPath('letter.raw_path');
        $this->getJson($url.'/forwards/'.$id)->assertJsonPath('forward.destination', $owner->email);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson($url)->assertJsonCount(0, 'forwards');
        $this->getJson($url.'/forwards/'.$id)->assertNotFound();
        $this->getJson($url.'/forwards/'.$id, ['X-Panel-User' => (string) $owner->id])->assertConflict();
        $mine->update(['is_pending' => true]);
        $this->postJson($url.'/forwards', $data)->assertNotFound();
    }

    public function test_bulk_status_reads_one_message_and_one_operation_batch_regardless_of_batch_size(): void
    {
        $owner = $this->owner();
        $message = $this->letter($owner);
        $ids = [];
        for ($index = 0; $index < 10; $index++) {
            $ids[] = MailForward::create(['message_id' => $message->id, 'mode' => 'manual',
                'initiator_id' => $owner->id, 'destination' => 'reader@example.test'])->id;
        }

        $this->actingAs($owner);
        foreach ([[$ids[0]], $ids] as $batch) {
            DB::enableQueryLog();
            try {
                $forwards = $this->getJson("/admin/messages/{$message->id}/forwards?".http_build_query(['ids' => $batch]))
                    ->assertOk()->json('forwards');
                $queries = array_filter(DB::getQueryLog(), fn (array $query) => preg_match('/\bfrom "(?:messages|mail_forwards)"/i', $query['query']));
                $this->assertCount(2, $queries);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertEqualsCanonicalizing($batch, array_column($forwards, 'id'));
        }
        $this->getJson("/admin/messages/{$message->id}/forwards?".http_build_query(['ids' => [...$ids, (string) Str::uuid()]]))
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    public function test_bulk_status_omits_invisible_or_missing_operations_and_returns_terminal_statuses(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->create(['is_admin' => true]);
        $message = $this->letter($owner);
        $other = $this->letter($owner);
        $data = ['message_id' => $message->id, 'destination' => 'reader@example.test'];
        $automatic = MailForward::create($data + ['mode' => 'auto']);
        $manual = MailForward::create($data + ['mode' => 'manual', 'initiator_id' => $admin->id]);
        $accepted = MailForward::create($data + ['mode' => 'manual', 'initiator_id' => $owner->id, 'status' => 'accepted']);
        $differentMessage = MailForward::create(['message_id' => $other->id, 'mode' => 'manual', 'initiator_id' => $owner->id] + $data);
        $ids = [$automatic->id, $manual->id, $accepted->id, $differentMessage->id, (string) Str::uuid()];
        $url = "/admin/messages/{$message->id}/forwards?".http_build_query(compact('ids'));

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->owner())->getJson($url)->assertNotFound();
        $visible = $this->actingAs($owner)->getJson($url)->assertOk()->json('forwards');
        $this->assertEqualsCanonicalizing([$automatic->id, $accepted->id], array_column($visible, 'id'));
        $this->assertSame('accepted', array_column($visible, 'status', 'id')[$accepted->id]);
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonCount(1, 'forwards')->assertJsonPath('forwards.0.id', $manual->id);
        $this->getJson($url, ['X-Panel-User' => (string) $owner->id])->assertConflict();
        $message->update(['is_pending' => true]);
        $this->actingAs($owner)->getJson($url)->assertNotFound();
    }

    private function enableAutomatic(): void
    {
        config(['forwarding.auto_enabled' => true, 'forwarding.auto_enabled_at' => now()->subDay()->toISOString()]);
        Queue::fake();
    }

    public function test_rule_requires_verified_external_destination_and_applies_only_to_future_receipts(): void
    {
        $this->enableAutomatic();
        $owner = $this->owner();
        $this->actingAs($owner);
        MailboxDomain::create(['name' => 'receive.test', 'issuance_enabled' => false]);
        $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
        $this->putJson(self::RULE, ['current_password' => 'wrong'] + $setup)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson(self::RULE, ['to' => 'reader@RECEIVE.test'] + $setup)->assertUnprocessable()->assertJsonValidationErrors('to');
        $version = $this->putJson(self::RULE, $setup)->assertOk()->assertJsonPath('forwarding.status', 'pending')->json('forwarding.version');
        $job = Queue::pushed(SendForwardingVerification::class)->sole();
        $this->assertNotSame($job->code, MailboxForwardingRule::sole()->code_hash);
        $this->postJson(self::RULE.'/resend', compact('version'))->assertTooManyRequests();
        $before = $this->letter($owner);
        DB::transaction(fn () => ForwardingRequests::automatic($before));
        $this->travel(1)->second();
        $this->postJson(self::RULE.'/verify', ['version' => $version, 'code' => $job->code])->assertOk()->assertJsonPath('forwarding.status', 'active');
        $this->getJson('/admin')->assertJsonPath('forwarding.destination', 'reader@example.org')->assertJsonMissingPath('forwarding.code_hash');
        $after = $this->letter($owner);
        foreach ([$before, $after, $after] as $letter) {
            DB::transaction(fn () => ForwardingRequests::automatic($letter));
        }
        $this->assertSame($after->id, MailForward::sole()->message_id);
        $this->assertSame('queued', MailForward::sole()->status);
        $this->travel(1)->minute();
        $this->putJson(self::RULE, ['to' => 'another@example.org'] + $setup)->assertOk()->assertJsonPath('forwarding.version', $version + 1);
        $this->assertSame('cancelled', MailForward::sole()->status);
        $this->postJson(self::RULE.'/verify', ['version' => $version, 'code' => $job->code])->assertUnprocessable();
    }

    public function test_code_retry_and_attempt_limits_cannot_be_reset_by_resubmitting_the_same_form(): void
    {
        $this->enableAutomatic();
        $this->actingAs($this->owner());
        $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
        $version = $this->putJson(self::RULE, $setup)->assertOk()->json('forwarding.version');
        $this->putJson(self::RULE, $setup)->assertTooManyRequests();
        $code = Queue::pushed(SendForwardingVerification::class)->sole()->code;
        $wrong = $code === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::RULE.'/verify', ['code' => $wrong, 'version' => $version])->assertUnprocessable();
        }
        $this->postJson(self::RULE.'/verify', compact('code', 'version'))->assertUnprocessable();
        $this->travel(60)->seconds();
        $this->postJson(self::RULE.'/resend', compact('version'))->assertOk();
        $this->travel(10)->minutes();
        $code = Queue::pushed(SendForwardingVerification::class)->last()->code;
        $this->postJson(self::RULE.'/verify', compact('version', 'code'))->assertUnprocessable();
    }

    public function test_closure_and_admin_password_reset_cancel_waiting_work_but_leave_a_submission_alone(): void
    {
        config(['forwarding.manual_enabled' => true]);
        $this->enableAutomatic();
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (['password_reset', 'mailbox_closed'] as $reason) {
            $owner = $this->owner();
            $this->actingAs($owner);
            $mine = $this->letter($owner);
            $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
            $version = 3;
            MailboxForwardingRule::create(['email_id' => $owner->mailbox->id, 'destination' => $setup['to'],
                'status' => 'pending', 'version' => $version, 'code_hash' => str_repeat('c', 64)]);
            $ids = [];
            foreach (['preparing', 'submitting'] as $status) {
                $id = $this->postJson("/admin/messages/{$mine->id}/forwards", [
                    'request_id' => (string) Str::uuid(), 'to' => $setup['to'],
                ])->assertAccepted()->json('forward.id');
                MailForward::findOrFail($id)->update(compact('status'));
                $ids[$status] = $id;
            }
            $this->putJson('/admin/account/password', $setup + ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
            $this->assertDatabaseHas('mailbox_forwarding_rules', ['email_id' => $owner->mailbox->id, 'status' => 'pending', 'version' => $version]);
            if ($reason === 'password_reset') {
                $this->flushSession();
                $this->actingAs($admin)->postJson("/admin/mailboxes/{$owner->mailbox->id}/password", ['return_credentials' => true])->assertOk();
            } else {
                $owner->mailbox->update(['closed_at' => now()]);
            }
            $this->assertDatabaseHas('mailbox_forwarding_rules', ['email_id' => $owner->mailbox->id, 'status' => 'disabled',
                'version' => $version + 1, 'code_hash' => null, 'disabled_reason' => $reason]);
            $this->assertDatabaseHas('mail_forwards', ['id' => $ids['preparing'], 'status' => 'cancelled', 'error_code' => $reason]);
            $this->assertDatabaseHas('mail_forwards', ['id' => $ids['submitting'], 'status' => 'submitting']);
        }
    }

    public function test_auto_reserves_future_capacity_and_excludes_pre_enable_receipts_and_local_destinations(): void
    {
        $this->enableAutomatic();
        $this->travelTo(now()->startOfHour()->addMinute());
        config(['forwarding.limits.total' => 1]);
        $owner = $this->owner();
        $rule = MailboxForwardingRule::create(['email_id' => $owner->mailbox->id, 'destination' => 'external@reader.test',
            'status' => 'active', 'version' => 7, 'activated_at' => now()->subDay()]);
        $first = $this->letter($owner);
        $second = $this->letter($owner);
        foreach ([$first, $second] as $message) {
            DB::transaction(fn () => ForwardingRequests::automatic($message));
        }
        $this->assertSame('queued', MailForward::where('message_id', $second->id)->sole()->status);
        $this->assertTrue(MailForward::where('message_id', $second->id)->sole()->next_attempt_at->eq(now()->addHour()->startOfHour()));
        $this->assertSame('quota_deferred', $rule->fresh()->last_error_code);
        config(['forwarding.auto_enabled_at' => now()->addMicrosecond()->toISOString()]);
        DB::transaction(fn () => ForwardingRequests::automatic($this->letter($owner)));
        $this->assertDatabaseCount('mail_forwards', 2);
        config(['forwarding.auto_enabled_at' => now()->toISOString()]);
        MailboxDomain::create(['name' => 'reader.test', 'issuance_enabled' => false]);
        DB::transaction(fn () => ForwardingRequests::automatic($this->letter($owner)));
        $this->assertDatabaseHas('mailbox_forwarding_rules', ['id' => $rule->id, 'status' => 'disabled', 'disabled_reason' => 'destination_local']);
        $this->assertSame(2, MailForward::where('status', 'cancelled')->count());
    }

    public function test_codes_are_queued_encrypted_and_request_limits_apply_across_mailboxes(): void
    {
        config(['forwarding.auto_enabled' => true, 'forwarding.auto_enabled_at' => now()->toISOString(), 'forwarding.limits.codes_per_destination' => 1]);
        $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
        $this->actingAs($this->owner())->putJson(self::RULE, $setup)->assertOk();
        $stored = DB::table('jobs')->where('queue', 'forwarding')->sole();
        $command = json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'];
        $job = unserialize(decrypt($command));
        $this->assertInstanceOf(SendForwardingVerification::class, $job);
        $this->assertNotEquals($job->code, MailboxForwardingRule::sole()->code_hash);
        $this->actingAs($this->owner())->putJson(self::RULE, $setup)->assertTooManyRequests();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_feature_switches_and_rule_removal_prevent_new_operations(): void
    {
        $owner = $this->owner();
        $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
        $mine = $this->letter($owner);
        $url = "/admin/messages/{$mine->id}/forwards";
        $this->actingAs($owner)->getJson('/admin')->assertJsonPath('forwarding.sending_enabled', false);
        $this->postJson($url, ['request_id' => (string) Str::uuid()] + $setup)->assertUnprocessable()->assertJsonPath('code', 'mode_disabled');
        $this->enableAutomatic();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->putJson(self::RULE, $setup)->assertForbidden();
        $this->actingAs($owner);
        $version = $this->putJson(self::RULE, $setup)->assertOk()->json('forwarding.version');
        $code = Queue::pushed(SendForwardingVerification::class)->sole()->code;
        $this->postJson(self::RULE.'/verify', compact('version', 'code'))->assertOk();
        $fresh = $this->letter($owner);
        DB::transaction(fn () => ForwardingRequests::automatic($fresh, 'blocked_by_filter'));
        $this->getJson('/admin')->assertJsonPath('forwarding.last_error_code', 'blocked_by_filter');
        $this->getJson("/admin/messages/{$fresh->id}")->assertJsonPath('forwards.0.error_code', 'blocked_by_filter');
        DB::transaction(fn () => ForwardingRequests::automatic($this->letter($owner)));
        $this->deleteJson(self::RULE, ['version' => $version + 1])->assertUnprocessable();
        $this->deleteJson(self::RULE, compact('version'))->assertOk()->assertJsonPath('forwarding.status', 'disabled');
        DB::transaction(fn () => ForwardingRequests::automatic($this->letter($owner)));
        $this->assertDatabaseCount('mail_forwards', 2);
        $this->assertDatabaseHas('mail_forwards', ['status' => 'cancelled', 'error_code' => 'owner_disabled']);
    }

    public function test_a_permanent_owner_can_prepare_and_cancel_an_address_while_automatic_delivery_is_paused(): void
    {
        $owner = $this->owner();
        $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
        $this->actingAs(User::factory()->create(['is_admin' => true]))->putJson(self::RULE, $setup)->assertForbidden();
        $this->actingAs($owner)->getJson('/admin')->assertJsonPath('forwarding.status', 'disabled');
        $this->putJson(self::RULE, ['current_password' => 'wrong'] + $setup)->assertJsonValidationErrors('current_password');
        MailboxDomain::create(['name' => 'receive.test', 'issuance_enabled' => false]);
        $this->putJson(self::RULE, ['to' => 'me@receive.test'] + $setup)->assertJsonValidationErrors('to');
        $response = $this->putJson(self::RULE, $setup)->assertOk()->assertJsonPath('forwarding.sending_enabled', false)
            ->assertJsonPath('forwarding.status', 'pending')->assertJsonPath('forwarding.destination', $setup['to'])
            ->assertJsonPath('forwarding.code_expires_at', null)->assertJsonPath('forwarding.resend_at', null);
        $version = $response->json('forwarding.version');
        $this->assertDatabaseHas('mailbox_forwarding_rules', ['email_id' => $owner->mailbox->id, 'code_hash' => null, 'activated_at' => null]);
        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseEmpty('forwarding_quota_buckets');
        $this->postJson(self::RULE.'/resend', compact('version'))->assertForbidden();
        $this->postJson(self::RULE.'/verify', ['version' => $version, 'code' => '123456'])->assertForbidden();
        $this->deleteJson(self::RULE, compact('version'))->assertOk()->assertJsonPath('forwarding.status', 'disabled');
    }

    public function test_an_address_saved_during_pause_can_be_verified_after_sending_is_enabled(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $setup = ['to' => 'reader@example.org', 'current_password' => 'password'];
        $version = $this->putJson(self::RULE, $setup)->assertOk()->json('forwarding.version');
        $oldLetter = $this->letter($owner);
        $this->travel(1)->second();
        $this->enableAutomatic();
        $this->getJson('/admin')->assertJsonPath('forwarding.sending_enabled', true)->assertJsonPath('forwarding.code_expires_at', null);
        $this->postJson(self::RULE.'/verify', ['version' => $version, 'code' => '123456'])->assertJsonValidationErrors('code');
        $this->postJson(self::RULE.'/resend', compact('version'))->assertOk();
        $code = Queue::pushed(SendForwardingVerification::class)->sole()->code;
        $this->postJson(self::RULE.'/verify', compact('version', 'code'))->assertOk()->assertJsonPath('forwarding.status', 'active');
        DB::transaction(fn () => ForwardingRequests::automatic($oldLetter));
        $this->assertDatabaseEmpty('mail_forwards');
        DB::transaction(fn () => ForwardingRequests::automatic($this->letter($owner)));
        $this->assertDatabaseCount('mail_forwards', 1);
        config(['forwarding.auto_enabled' => false]);
        $this->getJson('/admin')->assertJsonPath('forwarding.status', 'active')->assertJsonPath('forwarding.sending_enabled', false);
        $this->deleteJson(self::RULE, compact('version'))->assertOk()->assertJsonPath('forwarding.status', 'disabled');
    }

    public function test_destination_normalization_preserves_quoted_and_unicode_local_parts_and_resolves_local_idns(): void
    {
        $this->enableAutomatic();
        config(['forwarding.manual_enabled' => true]);
        $owner = $this->owner();
        $this->actingAs($owner);
        MailboxDomain::create(['name' => 'xn--e1afmkfd.xn--p1ai', 'issuance_enabled' => false]);
        $this->putJson(self::RULE, ['current_password' => 'password', 'to' => 'имя@пример.рф'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $letter = $this->letter($owner);
        $url = "/admin/messages/{$letter->id}/forwards";
        $data = ['request_id' => (string) Str::uuid(), 'to' => '"A@B"@пример.рф'];
        $this->postJson($url, $data)->assertAccepted()->assertJsonPath('forward.destination', '"A@B"@xn--e1afmkfd.xn--p1ai');
        $this->postJson($url, ['request_id' => (string) Str::uuid(), 'to' => 'имя@пример.рф'])
            ->assertAccepted()->assertJsonPath('forward.destination', 'имя@xn--e1afmkfd.xn--p1ai');
    }
}
