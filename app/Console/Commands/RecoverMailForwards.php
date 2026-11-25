<?php

namespace App\Console\Commands;

use App\Jobs\SendMailForward;
use App\Models\MailForward;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class RecoverMailForwards extends Command
{
    protected $signature = 'mail:recover-forwards';

    protected $description = 'Recover abandoned forwarding jobs and close ambiguous submissions';

    public function handle(): int
    {
        $expired = MailForward::where('status', 'queued')->where('created_at', '<=', now()->subDay());
        $failure = ['status' => 'failed', 'error_code' => 'delivery_expired'];
        foreach ($expired->lazyById(100) as $op) {
            if ((clone $expired)->whereKey($op->id)->update($failure)) {
                $op->fill($failure)->logOutcome();
            }
        }
        foreach (['manual', 'auto'] as $mode) {
            if (! config('forwarding.'.$mode.'_enabled')) {
                MailForward::where('mode', $mode)->whereIn('status', ['queued', 'preparing'])
                    ->update(['status' => 'cancelled', 'error_code' => 'mode_disabled']);
            }
        }
        $this->recoverMissingJobs();
        $stale = MailForward::whereIn('status', ['preparing', 'submitting'])->where('updated_at', '<', now()->subMinutes(3));
        foreach ($stale->lazyById(100) as $row) {
            DB::transaction(function () use ($row, $stale) {
                $op = (clone $stale)->where('status', $row->status)->where('attempt_id', $row->attempt_id)->lockForUpdate()->find($row->id);
                if (! $op) {
                    return;
                }
                if ($op->status === 'submitting') {
                    $op->update(['status' => 'unknown', 'error_code' => 'submission_unknown']);
                    $op->logOutcome();
                } else {
                    $op->update(['status' => 'queued', 'attempt_id' => null, 'next_attempt_at' => null]);
                    Bus::dispatch(new SendMailForward($op->id));
                }
            }, 3);
        }

        return self::SUCCESS;
    }

    private function recoverMissingJobs(): void
    {
        // A queue reservation can exhaust its tries before the worker commits its first claim.
        $due = MailForward::where('status', 'queued')->where('updated_at', '<', now()->subMinutes(3))
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
        $name = DB::getDriverName() === 'pgsql'
            ? "case when payload is json then payload::json->>'displayName' end"
            : "case when json_valid(payload) then json_extract(payload, '$.displayName') end";
        // A release replaces its reserved row atomically, keeping the same display name.
        $job = DB::table(config('queue.connections.forwarding.table'))->selectRaw('1')
            ->whereRaw("queue = 'forwarding'")
            ->whereRaw("({$name}) = ? || mail_forwards.id", [SendMailForward::class.':'])
            ->limit(1);
        $missing = $due->whereRaw("({$job->toSql()}) is null", $job->getBindings());

        foreach ($missing->select('id')->lazyById(100) as $op) {
            DB::transaction(function () use ($missing, $op) {
                // Recheck the queue and claim the repair if schedulers overlap.
                if ((clone $missing)->whereKey($op->id)->update(['updated_at' => now()])) {
                    Bus::dispatch(new SendMailForward($op->id));
                }
            }, 3);
        }
    }
}
