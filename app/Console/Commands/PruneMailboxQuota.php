<?php

namespace App\Console\Commands;

use App\Services\MailboxSession;
use App\Services\MailboxActionLimit;
use App\Services\MailboxSocketGrant;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PruneMailboxQuota extends Command
{
    protected $signature = 'mail:prune-quota';

    protected $description = 'Remove expired mailbox and panel login counters from the database cache';

    public function handle(): int
    {
        $name = config('cache.limiter') ?? config('cache.default');
        $store = Cache::store($name)->getStore();
        if (! $store instanceof DatabaseStore) {
            return self::SUCCESS;
        }

        $table = $store->getConnection()->table(config("cache.stores.$name.table"));
        $table->where('expiration', '<=', now()->timestamp)->where(function ($query) use ($store) {
            foreach ([MailboxSession::ISSUE_LIMIT_PREFIX, MailboxActionLimit::PREFIX, MailboxSocketGrant::CACHE_PREFIX, 'mailbox-close:', 'panel-login:'] as $namespace) {
                $prefix = $store->getPrefix().$namespace;
                $query->orWhereRaw('substr(key, 1, ?) = ?', [mb_strlen($prefix), $prefix]);
            }
        })->delete();

        return self::SUCCESS;
    }
}
