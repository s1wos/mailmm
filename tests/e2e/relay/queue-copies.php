<?php

use App\Console\Commands\WorkMailForwards;
use App\Jobs\SendMailForward;
use App\Models\MailForward;
use App\Models\Message;
use App\Models\User;
use App\Services\ForwardingRequests;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$scenario = $argv[1] ?? null;

if (in_array($scenario, ['restart-before-listen', 'restart-buffered'], true)) {
    Cache::forget('illuminate:queue:restart');
    $kernel = $app->make(Kernel::class);
    $restart = fn () => $kernel->call('queue:restart');
    if ($scenario === 'restart-before-listen') {
        Event::listen(WorkerStarting::class, $restart);
    } else {
        // Arm a restart between two iterations, after the worker has subscribed.
        Queue::connection('forwarding')->push(new SendMailForward((string) Str::uuid()));
        Event::listen(JobProcessed::class, function () use ($restart) {
            Event::listen(JobPopping::class, function () use ($restart) {
                DB::statement("select pg_notify(?, '')", [WorkMailForwards::CHANNEL]);
                $restart();
            });
        });
    }
    Log::listen(function (MessageLogged $event) {
        if ($event->level === 'error') {
            echo $event->message."\n";
        }
    });
    Event::listen(WorkerStopping::class, function (WorkerStopping $event) {
        echo $event->reason?->value."\n";
    });
    exit($kernel->call('mail:work-forwards'));
}

if ($scenario === 'deadline-indexes') {
    $later = now()->addHour()->timestamp;
    $payload = '{"displayName":"deadline-indexes"}';
    DB::insert(<<<'SQL'
        insert into jobs (queue, payload, attempts, reserved_at, available_at, created_at)
        select 'forwarding', ?, 0, case when n % 2 = 0 then null else cast(? as integer) end, ?, ?
        from generate_series(1, 12000) as n
        SQL, [$payload, $later, $later, now()->timestamp]);
    DB::statement('analyze jobs');
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'min(') && str_contains($query->sql, '"jobs"')) {
            $plan = DB::selectOne('EXPLAIN (FORMAT JSON) '.$query->sql, $query->bindings);
            echo $plan->{'QUERY PLAN'};
            app('queue.worker')->shouldQuit = true;
        }
    });
    try {
        $status = $app->make(Kernel::class)->call('mail:work-forwards');
    } finally {
        DB::table('jobs')->where('payload', $payload)->delete();
    }
    exit($status);
}

if ($scenario === 'transaction') {
    $admin = User::where('email', 'admin@relay.test')->firstOrFail();
    $archive = Message::where('subject', 'Archived relay test')->firstOrFail();

    DB::transaction(function () use ($admin, $archive) {
        $operation = ForwardingRequests::manual($admin, $archive->id, 'archive@reader.test', (string) Str::uuid());
        echo $operation->id."\n";
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'commit') {
            throw new RuntimeException('Missing test transaction commit signal');
        }
    });

    exit;
}

if (in_array($scenario, ['retry', 'reservation'], true)) {
    $admin = User::where('email', 'admin@relay.test')->firstOrFail();
    $original = Message::where('subject', 'Original relay test')->firstOrFail();
    $when = now()->startOfSecond()->addSeconds(5);

    $reserved = $scenario === 'reservation';

    DB::transaction(function () use ($admin, $original, $when, $reserved) {
        $operation = MailForward::create([
            'message_id' => $original->id,
            'initiator_id' => $admin->id,
            'request_id' => (string) Str::uuid(),
            'mode' => 'manual',
            'destination' => 'original@reader.test',
            'status' => 'queued',
            'attempts' => 1,
            'error_code' => 'smtp_temporary',
            'next_attempt_at' => $reserved ? null : $when,
        ]);
        $queue = Queue::connection('forwarding');
        $job = new SendMailForward($operation->id);
        if ($reserved) {
            $id = $queue->pushOn('forwarding', $job);
            $retryAfter = (int) config('queue.connections.forwarding.retry_after');
            DB::table(config('queue.connections.forwarding.table'))->where('id', $id)->update([
                'reserved_at' => $when->timestamp - $retryAfter,
                'attempts' => 1,
            ]);
        } else {
            $queue->laterOn('forwarding', $when, $job);
        }
    });

    echo $when->timestamp;

    exit;
}

$admin = new User(['name' => 'Relay test', 'email' => 'admin@relay.test', 'password' => Str::random(32)]);
$admin->is_admin = true;
$admin->save();

$archive = Message::create([
    'recipient' => 'owner@relay.test',
    'from' => 'author@sender.test',
    'subject' => 'Archived relay test',
    'body_text' => 'Plain archive copy',
    'body_html' => '<p>HTML archive copy</p>',
    'received_at' => now(),
]);
$raw = "From: Author <author@sender.test>\r\nTo: owner@relay.test\r\nSubject: Original relay test\r\n"
    ."Date: Wed, 25 Nov 2026 10:00:00 +0000\r\nMessage-ID: <original@sender.test>\r\n\r\nOriginal body.\r\n";
Storage::disk('local')->put('relay-original.eml', $raw);
$original = Message::create([
    'recipient' => 'owner@relay.test',
    'from' => 'author@sender.test',
    'subject' => 'Original relay test',
    'received_at' => now(),
    'raw_path' => 'relay-original.eml',
    'raw_size' => strlen($raw),
    'raw_sha256' => hash('sha256', $raw),
    'forwarding_context' => ['authentication_results' => 'mx.relay.test; dkim=none'],
]);

ForwardingRequests::manual($admin, $archive->id, 'archive@reader.test', (string) Str::uuid());
ForwardingRequests::manual($admin, $original->id, 'original@reader.test', (string) Str::uuid());
