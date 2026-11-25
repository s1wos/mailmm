<?php

namespace App\Console\Commands;

use App\Services\ContainerErrorParser;
use App\Services\ContainerErrorStreams;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneContainerErrors extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Remove expired container errors and redact stored SMTP addresses';

    public function handle(): int
    {
        $logs = DB::table('admin_error_logs');
        $cutoff = now()->utc()->subDays(ContainerErrorStreams::RETENTION_DAYS)->format('Y-m-d H:i:s.uP');
        (clone $logs)->where('occurred_at', '<=', $cutoff)->delete();

        $unredacted = (clone $logs)->where('service', 'smtp')->where('message', 'like', '%@%');
        foreach ($unredacted->select(['id', 'message'])->lazyById(200) as $entry) {
            $message = ContainerErrorParser::redactMailAddresses($entry->message);
            if ($message !== $entry->message) {
                (clone $logs)->where('id', $entry->id)->update(['message' => $message]);
            }
        }

        return self::SUCCESS;
    }
}
