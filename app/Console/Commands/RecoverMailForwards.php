<?php

namespace App\Console\Commands;

use App\Jobs\SendMailForward;
use App\Models\MailForward;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $missing = $due->pluck('id')->flip();
        if ($missing->isEmpty()) {
            return;
        }

        // Reserved jobs and jobs released with a new database ID are both still live.
        $jobs = DB::table(config('queue.connections.forwarding.table'))->where('queue', 'forwarding');
        $lastId = $jobs->max('id');
        if ($lastId !== null) {
            foreach ($jobs->where('id', '<=', $lastId)->select('id', 'payload')->lazyById(200) as $job) {
                $name = json_decode($job->payload, true)['displayName'] ?? '';
                $missing->forget(Str::after($name, SendMailForward::class.':'));
                if ($missing->isEmpty()) {
                    return;
                }
            }
        }
        foreach ($missing->keys() as $id) {
            DB::transaction(function () use ($due, $id) {
                // Updating the stale timestamp claims the repair even if two schedulers overlap.
                if ((clone $due)->whereKey($id)->update(['updated_at' => now()])) {
                    Bus::dispatch(new SendMailForward($id));
                }
            }, 3);
        }
    }
}
