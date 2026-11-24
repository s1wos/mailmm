<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ForwardingQuota
{
    // The caller's transaction owns both the reservation and the queued job.
    // One locked row per UTC hour serializes admission even for previously unseen destinations.
    public static function reserve(string $mode, int $actor, string $destination, int $bytes): ?CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $destinationKey = hash_hmac('sha256', strtolower($destination), config('app.key'));
        $perActor = match ($mode) {
            'verification' => 'codes_per_mailbox',
            'manual' => 'manual_per_user',
            'auto' => 'auto_per_mailbox',
        };
        $perDestination = $mode === 'verification' ? 'codes_per_destination' : 'per_destination';
        $charges = [
            "$perActor:$actor" => [$perActor, 1],
            "$perDestination:$destinationKey" => [$perDestination, 1],
            'total' => ['total', 1],
            'bytes' => ['bytes', $bytes],
        ];
        $deadline = $mode === 'auto' ? $now->addHours(24) : $now->addSecond();
        for ($hour = $now->startOfHour(); $hour->lt($deadline); $hour = $hour->addHour()) {
            $rows = DB::table('forwarding_quota_buckets')->where('hour', $hour->timestamp);
            DB::table('forwarding_quota_buckets')->insertOrIgnore(['hour' => $hour->timestamp, 'scope' => 'total']);
            (clone $rows)->where('scope', 'total')->lockForUpdate()->first();
            $used = (clone $rows)->whereIn('scope', array_keys($charges))->pluck('used', 'scope');
            foreach ($charges as $scope => [$name, $amount]) {
                $limit = (int) config("forwarding.limits.$name");
                if ($amount > $limit) {
                    return null;
                }
                if ($amount + $used->get($scope, 0) > $limit) {
                    if ($mode === 'auto' && in_array($scope, ['total', 'bytes'], true)) {
                        continue 2;
                    }

                    return null;
                }
            }
            foreach ($charges as $scope => [, $amount]) {
                DB::table('forwarding_quota_buckets')->updateOrInsert(['hour' => $hour->timestamp, 'scope' => $scope],
                    ['used' => $used->get($scope, 0) + $amount]);
            }

            return $hour->max($now);
        }

        return null;
    }
}
