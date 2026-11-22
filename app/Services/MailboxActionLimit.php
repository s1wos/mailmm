<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class MailboxActionLimit
{
    public const PREFIX = 'mailbox-action:';

    public static function run(Request $request, string $action, Closure $perform, string $resource = '',
        int $burst = 1, bool $interactive = true): mixed
    {
        if ($request->user()?->is_admin) {
            return $perform();
        }

        $actor = $request->user() ? 'user:'.$request->user()->id : 'session:'.$request->session()->getId();
        $key = self::PREFIX.hash('sha256', json_encode([$actor, $action, $resource], JSON_THROW_ON_ERROR));
        $cache = Cache::store(config('cache.limiter'));
        $lock = $cache->lock($key.':pending', 20);
        if (! $lock->get()) {
            self::reject();
        }
        try {
            $window = $cache->get($key, ['deadlines' => [], 'actionUntil' => 0]);
            $now = now()->getTimestampMs();
            $deadlines = array_values(array_filter($window['deadlines'], fn ($until) => $until > $now));
            if (count($deadlines) >= $burst || ($interactive && $window['actionUntil'] > $now)) {
                self::reject();
            }
            $result = $perform();
            if (! $result instanceof Response || $result->isSuccessful()) {
                $until = now()->getTimestampMs() + 1000;
                $deadlines[] = $until;
                $cache->put($key, ['deadlines' => $deadlines, 'actionUntil' => $interactive ? $until : $window['actionUntil']], 2);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private static function reject(): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'action_rate_limited',
            'message' => 'Подождите секунду и повторите действие.',
        ], 429, ['Retry-After' => '1']));
    }
}
