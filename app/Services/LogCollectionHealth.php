<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LogCollectionHealth
{
    private const KEY = 'admin-error-log-health';
    public const SERVICES = ['app', 'worker', 'scheduler', 'reverb', 'migrate', 'smtp', 'nginx'];

    /** @param array<string, bool> $checks */
    public static function record(array $checks): void
    {
        $previous = Cache::get(self::KEY, []);
        $now = now();
        foreach (self::SERVICES as $service) {
            if (! collect(array_keys($checks))->contains(fn ($path) => str_starts_with($path, $service.'/'))) {
                $checks[$service.'/1'] = false;
            }
        }
        ksort($checks);
        $sources = [];
        foreach ($checks as $path => $success) {
            $sources[$path] = [
                'available' => $success,
                'last_success_at' => $success ? $now->toISOString() : ($previous['sources'][$path]['last_success_at'] ?? null),
            ];
        }
        Cache::forever(self::KEY, ['checked' => $now->timestamp, 'sources' => $sources]);
    }

    public static function discoveryFailed(): void
    {
        $previous = Cache::get(self::KEY, []);
        Cache::forever(self::KEY, ['checked' => null, 'sources' => $previous['sources'] ?? []]);
    }

    public static function snapshot(): array
    {
        $state = Cache::get(self::KEY, []);
        $online = ($state['checked'] ?? 0) > now()->timestamp - 45;
        $sources = $state['sources'] ?? [];
        if (! $online) {
            foreach ($sources as &$source) {
                $source['available'] = false;
            }
        }
        return ['online' => $online, 'sources' => (object) $sources];
    }
}
