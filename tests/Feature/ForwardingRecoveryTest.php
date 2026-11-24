<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\ForwardingRequests;
use App\Services\ForwardingTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForwardingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_replaces_only_missing_due_jobs_and_a_worker_delivers_once(): void
    {
        config(['forwarding.manual_enabled' => true, 'forwarding.service_address' => 'service@sender.test',
            'forwarding.bounce_domain' => 'bounce.sender.test']);
        Storage::fake('local');
        $raw = "From: shop@sender.test\r\nSubject: Receipt\r\n\r\nReceipt\r\n";
        Storage::disk('local')->put('mail/test.eml', $raw);
        $admin = User::factory()->create(['is_admin' => true]);
        $message = Message::create(['recipient' => 'owner@sender.test', 'from' => 'shop@sender.test', 'received_at' => now(), 'raw_path' => 'mail/test.eml',
            'raw_size' => strlen($raw), 'raw_sha256' => hash('sha256', $raw)]);
        $request = fn () => ForwardingRequests::manual($admin, $message->id, 'reader@example.org', (string) Str::uuid());
        $queue = Queue::connection('forwarding');
        $missing = $request();
        $queue->pop('forwarding')->delete();
        $deferred = $request();
        $queue->pop('forwarding')->delete();
        $deferred->update(['next_attempt_at' => now()->addHour()]);
        $reserved = $request();
        $waiting = $request();

        $this->travel(5)->minutes();
        $reservation = $queue->pop('forwarding');
        $this->artisan('mail:recover-forwards')->assertSuccessful();
        $this->artisan('mail:recover-forwards')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 3);
        $this->assertSame(1, $queue->reservedSize('forwarding'));

        $reservation->release(1800);
        $this->travel(5)->minutes();
        $this->artisan('mail:recover-forwards')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 3);
        $this->mock(ForwardingTransport::class)->shouldReceive('send')->twice();
        foreach ([$waiting, $missing] as $op) {
            $queue->pop('forwarding')->fire();
            $op->refresh();
            $this->assertSame('accepted', $op->status, $op->error_code ?? '');
        }
        $this->assertSame(1, $missing->fresh()->attempts);
        $this->assertSame('queued', $reserved->fresh()->status);
        $this->assertSame('queued', $deferred->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
    }
}
