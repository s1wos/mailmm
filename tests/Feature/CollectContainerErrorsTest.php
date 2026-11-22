<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ContainerErrorReaderState;
use App\Services\ContainerErrorStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectContainerErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_reports_a_stalled_reader_and_preserves_the_last_successful_check(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->travelTo('2026-11-22 01:00:00 UTC');
        Http::fake([
            '*/containers' => Http::sequence()->push([$this->container()])->push([$this->container()])->push([], 503),
            '*/logs/worker/1?*' => Http::sequence()->push('')->push('', 503),
        ]);
        $this->getJson('/admin/errors')->assertJsonPath('collection.online', false);
        $this->artisan('logs:collect --once')->assertSuccessful();
        $checked = now()->toISOString();
        $this->getJson('/admin/errors')->assertJsonPath('collection.online', true)
            ->assertJsonPath('collection.sources.worker/1.available', true)
            ->assertJsonPath('collection.sources.worker/1.last_success_at', $checked)
            ->assertJsonPath('collection.sources.smtp/1.available', false);
        $this->travel(46)->seconds();
        $this->getJson('/admin/errors')->assertJsonPath('collection.online', false)
            ->assertJsonPath('collection.sources.worker/1.available', false);
        $this->travelTo($checked);
        $this->travel(5)->seconds();
        $this->artisan('logs:collect --once')->assertFailed();
        $this->getJson('/admin/errors')->assertJsonPath('collection.sources.worker/1.available', false)
            ->assertJsonPath('collection.sources.worker/1.last_success_at', $checked);
        $this->artisan('logs:collect --once')->assertFailed();
        $this->getJson('/admin/errors')->assertJsonPath('collection.online', false);
        $this->travel(2)->minutes();
        $this->getJson('/admin/errors')->assertJsonPath('collection.online', false)
            ->assertJsonPath('collection.sources.worker/1.last_success_at', $checked);
    }

    public function test_imports_retained_errors_and_replaying_the_docker_window_is_idempotent(): void
    {
        $stream = $this->frame('2026-01-01T01:02:03.123456789Z [2026-01-01 01:02:03] production.ERROR: Missing mail index')
            .$this->frame('2026-01-01T01:02:03.123456790Z [2026-01-01 01:02:03] production.INFO: Started');
        Http::fake([
            '*/containers' => Http::response([$this->container()]),
            '*/logs/worker/1?*' => Http::sequence()->push($stream)->push($stream),
        ]);
        $this->artisan('logs:collect --once')->assertSuccessful();
        Cache::flush();
        $this->artisan('logs:collect --once')->assertSuccessful();
        $this->assertDatabaseCount('admin_error_logs', 1);
        $row = DB::table('admin_error_logs')->first();
        $this->assertSame('worker', $row->service);
        $this->assertSame('error', $row->level);
        $this->assertStringContainsString('Missing mail index', $row->message);
        $this->assertStringStartsWith('2026-01-01 01:02:03.123456', $row->occurred_at);
    }

    public function test_does_not_advance_past_an_interrupted_log_stream(): void
    {
        $stream = $this->frame('2026-01-01T01:02:03.000000000Z [2026-01-01 01:02:03] production.ERROR: Recovered');
        Http::fake([
            '*/containers' => Http::response([$this->container()]),
            '*/logs/worker/1?*' => Http::sequence()->push(substr($stream, 0, -5))->push($stream),
        ]);
        $this->artisan('logs:collect --once')->assertFailed();
        $this->artisan('logs:collect --once')->assertSuccessful();
        $this->assertDatabaseCount('admin_error_logs', 1);
        $this->assertSame(2, Http::recorded(fn ($request) => str_contains($request->url(), 'since=0&'))->count());
    }

    public function test_follow_reports_a_write_in_progress_until_the_error_is_stored(): void
    {
        $line = $this->frame('2026-01-01T01:02:03.001000000Z [2026-01-01 01:02:03] production.ERROR: Health fixture');
        Http::fake(['*/logs/worker/1?*' => Http::response($line)]);
        $ready = null;
        $observations = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$ready, &$observations) {
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'admin_error_logs')) {
                $observations[] = $ready;
            }
        });
        $source = ['path' => 'worker/1', 'service' => 'worker', 'exited' => false];
        app(ContainerErrorStreams::class)->read('worker-id', $source, true, function (bool $state) use (&$ready) {
            $ready = $state;
        });
        $this->assertSame([false], $observations);
        $this->assertTrue($ready);
        $this->assertDatabaseCount('admin_error_logs', 1);
    }

    public function test_busy_reader_is_healthy_only_if_a_write_completes_during_the_probe(): void
    {
        $reader = new ContainerErrorReaderState;
        $reader->beginProbe();
        $reader->receive('+-');
        $this->assertFalse($reader->healthy());

        $reader->beginProbe();
        $reader->receive('+-+');
        $reader->receive('-');
        $this->assertTrue($reader->healthy());

        $reader->beginProbe();
        $this->assertFalse($reader->healthy());
        $reader->receive('+');
        $reader->beginProbe();
        $this->assertTrue($reader->healthy());
    }

    public function test_follow_commits_each_error_before_the_next_frame_and_resumes_after_a_disconnect(): void
    {
        $first = $this->frame('2026-01-01T01:02:03.001000000Z [2026-01-01 01:02:03] production.ERROR: First');
        $second = $this->frame('2026-01-01T01:02:04.002000000Z [2026-01-01 01:02:04] production.ERROR: Second');
        Http::fake([
            '*/containers' => Http::response([$this->container()]),
            '*/logs/worker/1?*' => Http::sequence()->push($first.substr($second, 0, -5))->push($first.$second),
        ]);
        $this->artisan('logs:collect --source=worker-id')->assertFailed();
        $this->assertDatabaseCount('admin_error_logs', 1);
        $this->artisan('logs:collect --source=worker-id')->assertSuccessful();
        $this->assertDatabaseCount('admin_error_logs', 2);
        Http::assertSent(fn ($request) => $request->url() === 'http://log-reader:8080/logs/worker/1?since=1767229323&follow=1');
    }

    public function test_identical_lines_from_two_replicas_remain_distinct(): void
    {
        $line = $this->frame('2026-01-01T01:02:03.001000000Z [2026-01-01 01:02:03] production.ERROR: Job failed');
        Http::fake([
            '*/containers' => Http::response([$this->container(), $this->container('second-id', '2')]),
            '*/logs/worker/*' => fn () => Http::response($line),
        ]);
        $this->artisan('logs:collect --once')->assertSuccessful();
        $this->assertDatabaseCount('admin_error_logs', 2);
    }

    public function test_a_replaced_container_read_by_two_followers_does_not_duplicate_its_log(): void
    {
        $first = $this->container('previous-id');
        $replacement = $this->container('replacement-id');
        $line = $this->frame('2026-01-01T01:02:03.001000000Z [2026-01-01 01:02:03] production.ERROR: New instance');
        Http::fake([
            '*/containers' => Http::sequence()->push([$first])->push([$replacement]),
            '*/logs/worker/1?*' => fn () => Http::response($line),
        ]);
        $this->artisan('logs:collect --source=previous-id')->assertSuccessful();
        $this->artisan('logs:collect --source=replacement-id')->assertSuccessful();
        $this->assertDatabaseCount('admin_error_logs', 1);
    }

    private function container(string $id = 'worker-id', string $replica = '1'): array
    {
        return ['Id' => $id, 'State' => 'running', 'Names' => ['/mailmm-worker-'.$replica], 'Labels' => [
            'com.docker.compose.project' => 'mailmm',
            'com.docker.compose.service' => 'worker',
            'com.docker.compose.container-number' => $replica,
            'com.docker.compose.oneoff' => 'False',
        ]];
    }

    private function frame(string $line): string
    {
        return pack('CxxxN', 2, strlen($line) + 1).$line."\n";
    }
}
