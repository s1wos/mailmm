<?php

namespace App\Services;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;

final class MailboxNotifications
{
    public static function publish(ShouldBroadcast $event): void
    {
        $queue = config('queue.connections.'.config('queue.default'));
        $connection = $queue['connection'] ?? config('database.default');
        if (($queue['driver'] ?? null) === 'database' && $connection === DB::connection()->getName()) {
            event($event);

            return;
        }

        DB::afterCommit(static fn () => rescue(static fn () => event($event)));
    }
}
