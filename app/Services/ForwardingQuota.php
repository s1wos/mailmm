<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ForwardingQuota
{
    // The caller's transaction owns both the reservation and the queued job.
    // The total row serializes reservations for the hour being charged.
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
        foreach ($charges as [$name, $amount]) {
            if ($amount > (int) config("forwarding.limits.$name")) {
                return null;
            }
        }

        $deadline = $mode === 'auto' ? $now->addHours(24) : $now->addSecond();
        $scopes = array_keys($charges);
        $future = null;
        for ($hour = $now->startOfHour(); $hour->lt($deadline); $hour = $hour->addHour()) {
            $timestamp = $hour->timestamp;
            $used = $future?->get($timestamp)?->pluck('used', 'scope') ?? collect();
            $blocked = self::blockedScope($charges, $used);
            if ($future === null || $blocked === null) {
                $used = self::lockAndRead($timestamp, $scopes);
                $blocked = self::blockedScope($charges, $used);
            }
            if ($blocked !== null) {
                if ($mode !== 'auto' || ! in_array($blocked, ['total', 'bytes'], true)) {
                    return null;
                }

                // Usage in future hours can only increase. Skip full hours
                // from one read and recheck every candidate before charging it.
                $future ??= DB::table('forwarding_quota_buckets')->where('hour', '>', $timestamp)
                    ->where('hour', '<', $deadline->timestamp)->whereIn('scope', $scopes)
                    ->get(['hour', 'scope', 'used'])->groupBy('hour');

                continue;
            }

            $buckets = [];
            foreach ($charges as $scope => [, $amount]) {
                $buckets[] = ['hour' => $timestamp, 'scope' => $scope, 'used' => $used->get($scope, 0) + $amount];
            }
            DB::table('forwarding_quota_buckets')->upsert($buckets, ['hour', 'scope'], ['used']);

            return $hour->max($now);
        }

        return null;
    }

    private static function lockAndRead(int $hour, array $scopes): Collection
    {
        DB::table('forwarding_quota_buckets')->insertOrIgnore(['hour' => $hour, 'scope' => 'total']);
        $rows = DB::table('forwarding_quota_buckets')->where('hour', $hour);
        (clone $rows)->where('scope', 'total')->lockForUpdate()->first();

        return $rows->whereIn('scope', $scopes)->pluck('used', 'scope');
    }

    private static function blockedScope(array $charges, Collection $used): ?string
    {
        foreach ($charges as $scope => [$name, $amount]) {
            if ($amount + $used->get($scope, 0) > (int) config("forwarding.limits.$name")) {
                return $scope;
            }
        }

        return null;
    }
}
