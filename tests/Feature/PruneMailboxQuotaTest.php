<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneMailboxQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_removes_only_expired_mailbox_and_login_counters_and_timers(): void
    {
        config(['cache.limiter' => 'database', 'cache.prefix' => 'mail_mm!%:']);
        $id = $this->getJson('/api/email')->assertOk()->json('data.id');
        $this->deleteJson('/api/email', headers: ['X-Expected-Mailbox-Id' => $id])->assertNoContent();
        $this->getJson('/api/email/options')->assertOk();
        $this->postJson('/admin/login')->assertUnprocessable();
        $cache = Cache::store('database');
        $cache->put('unrelated', 'untouched', 60);
        $this->travel(1)->hour();
        $prefixes = ['guest-mailbox-issue:', 'mailbox-close:', 'panel-login:', 'mailbox-action:'];
        foreach ($prefixes as $prefix) {
            $cache->put($prefix.'active', 3, 60);
        }
        $foreign = 'mailXmm!%:guest-mailbox-issue:outside';
        DB::table('cache')->insert(['key' => $foreign, 'value' => '1', 'expiration' => now()->timestamp - 1]);

        $this->assertDatabaseCount('cache', 13);
        $this->artisan('mail:prune-quota')->assertSuccessful();
        $this->assertDatabaseCount('cache', 6);
        foreach ($prefixes as $prefix) {
            $this->assertSame(3, $cache->get($prefix.'active'));
        }
        $this->assertDatabaseHas('cache', ['key' => 'mail_mm!%:unrelated']);
        $this->assertDatabaseHas('cache', ['key' => $foreign]);
        $this->artisan('mail:prune-quota')->assertSuccessful();
    }
}
