<?php

namespace Tests\Feature;

use App\Services\ForwardingQuota;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForwardingQuotaTest extends TestCase
{
    use RefreshDatabase;

    public static function quotaWindows(): array
    {
        return [
            'current hour' => [0, 4],
            'next hour' => [1, 8],
            'end of the window' => [24, 8],
        ];
    }

    #[DataProvider('quotaWindows')]
    public function test_reserving_quota_has_a_bounded_query_count(int $fullHours, int $maxQueries): void
    {
        $now = CarbonImmutable::parse('2026-11-25 10:15:00', 'UTC');
        $this->travelTo($now);
        config(['forwarding.limits.total' => 2, 'forwarding.limits.bytes' => 4096,
            'forwarding.limits.auto_per_mailbox' => 10, 'forwarding.limits.per_destination' => 10]);
        for ($offset = 0; $offset < $fullHours; $offset++) {
            DB::table('forwarding_quota_buckets')->insert([
                'hour' => $now->startOfHour()->addHours($offset)->timestamp, 'scope' => 'total', 'used' => 2,
            ]);
        }

        $connection = DB::connection();
        $connection->enableQueryLog();
        try {
            $scheduled = $connection->transaction(fn () => ForwardingQuota::reserve('auto', 21, 'reader@example.test', 1024));
            $queries = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $expected = $fullHours === 0 ? $now : $now->startOfHour()->addHours($fullHours);
        $this->assertSame($expected->timestamp, $scheduled?->timestamp);
        $this->assertLessThanOrEqual($maxQueries, $queries);
        $this->assertDatabaseHas('forwarding_quota_buckets', ['hour' => $expected->startOfHour()->timestamp, 'scope' => 'total', 'used' => 1]);
        $this->assertDatabaseHas('forwarding_quota_buckets', ['hour' => $expected->startOfHour()->timestamp, 'scope' => 'bytes', 'used' => 1024]);
    }

    public function test_a_personal_limit_in_a_full_hour_still_rejects_reservation(): void
    {
        $now = CarbonImmutable::parse('2026-11-25 10:15:00', 'UTC');
        $this->travelTo($now);
        config(['forwarding.limits.total' => 2, 'forwarding.limits.auto_per_mailbox' => 1]);
        $nextHour = $now->startOfHour()->addHour()->timestamp;
        DB::table('forwarding_quota_buckets')->insert([
            ['hour' => $now->startOfHour()->timestamp, 'scope' => 'total', 'used' => 2],
            ['hour' => $nextHour, 'scope' => 'total', 'used' => 2],
            ['hour' => $nextHour, 'scope' => 'auto_per_mailbox:21', 'used' => 1],
        ]);

        $this->assertNull(DB::transaction(fn () => ForwardingQuota::reserve('auto', 21, 'reader@example.test', 1024)));
        $this->assertDatabaseMissing('forwarding_quota_buckets', ['hour' => $nextHour + 3600]);
    }
}
