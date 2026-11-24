<?php

namespace App\Console\Commands;

use App\Http\Middleware\ThrottleOrdinaryUser;
use App\Services\MailboxActionLimit;
use App\Services\MailboxSession;
use App\Services\MailboxSocketGrant;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PruneMailboxQuota extends Command
{
    protected $signature = 'mail:prune-quota';

    protected $description = 'Remove expired mailbox and panel login counters from the database cache';

    public function handle(): int
    {
        DB::table('forwarding_quota_buckets')->where('hour', '<', now('UTC')->startOfHour()->timestamp)->delete();
        $name = config('cache.limiter') ?? config('cache.default');
        $store = Cache::store($name)->getStore();
        if (! $store instanceof DatabaseStore) {
            return self::SUCCESS;
        }

        $table = $store->getConnection()->table(config("cache.stores.$name.table"));
        $table->where('expiration', '<=', now()->timestamp)->where(function ($query) use ($store) {
            $namespaces = [
                MailboxSession::ISSUE_LIMIT_PREFIX,
                MailboxActionLimit::PREFIX,
                MailboxSocketGrant::CACHE_PREFIX,
                ...ThrottleOrdinaryUser::PREFIXES,
            ];
            foreach ($namespaces as $namespace) {
                $prefix = $store->getPrefix().$namespace;
                $query->orWhereRaw('substr(key, 1, ?) = ?', [mb_strlen($prefix), $prefix]);
            }
        })->delete();

        return self::SUCCESS;
    }
}
