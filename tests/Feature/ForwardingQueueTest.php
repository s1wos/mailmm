<?php

namespace Tests\Feature;

use App\Jobs\SendMailForward;
use App\Models\MailForward;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForwardingQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_repairs_missing_due_jobs_via_indexed_probes(): void
    {
        $this->freezeSecond();
        config(['forwarding.manual_enabled' => true]);

        $message = Message::create(['recipient' => 'owner@mailmm.test', 'from' => 'author@sender.test',
            'subject' => 'Forwarding recovery', 'received_at' => now()]);
        $queued = fn (array $fields = []) => MailForward::create($fields + [
            'message_id' => $message->id, 'mode' => 'manual', 'destination' => 'reader@outside.test',
            'status' => 'queued', 'updated_at' => now()->subMinutes(5),
        ]);
        $reserved = $queued();
        $released = $queued();
        $missing = $queued();
        $deferred = $queued(['next_attempt_at' => now()->addMinute()]);

        $queue = Queue::connection('forwarding');
        $reservedJobId = $queue->push(new SendMailForward($reserved->id));
        $releaseJobId = $queue->push(new SendMailForward($released->id));
        $queue->pushOn('default', new SendMailForward($missing->id));
        $queue->pop();
        $queue->pop()->release(60);

        $jobs = DB::table('jobs');
        $noise = ['queue' => 'forwarding', 'payload' => '{"displayName":"OtherJob"}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp];
        $jobs->insert(array_fill(0, 405, $noise));
        $jobs->insert(['payload' => 'invalid json'] + $noise);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('analyze jobs');
        }

        DB::enableQueryLog();
        $this->artisan('mail:recover-forwards')->assertSuccessful();
        $jobReads = collect(DB::getQueryLog())->filter(fn ($entry) => str_starts_with($entry['query'], 'select')
            && str_contains($entry['query'], '"jobs"'))->values();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $this->assertCount(1, $jobReads);
        $read = $jobReads->first();
        if (DB::getDriverName() === 'sqlite') {
            $plan = DB::select('EXPLAIN QUERY PLAN '.$read['query'], $read['bindings']);
            $this->assertStringContainsString('SEARCH jobs USING INDEX jobs_forwarding_name_index', implode(' ', array_column($plan, 'detail')));
        } else {
            $plan = DB::selectOne('EXPLAIN (FORMAT JSON) '.$read['query'], $read['bindings']);
            $this->assertStringContainsString('jobs_forwarding_name_index', $plan->{'QUERY PLAN'});
        }

        $this->assertSame(409, (clone $jobs)->where('queue', 'forwarding')->count());
        $this->travel(4)->minutes();
        $this->artisan('mail:recover-forwards')->assertSuccessful();

        $this->assertNotNull((clone $jobs)->where('id', $reservedJobId)->value('reserved_at'));
        $this->assertFalse((clone $jobs)->where('id', $releaseJobId)->exists());
        $counts = (clone $jobs)->where('queue', 'forwarding')->pluck('payload')
            ->map(fn ($payload) => json_decode($payload, true)['displayName'] ?? null)->countBy();
        foreach ([$reserved, $released, $missing, $deferred] as $operation) {
            $this->assertSame(1, $counts[(new SendMailForward($operation->id))->displayName()]);
        }
    }
}
