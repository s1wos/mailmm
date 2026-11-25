<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use PDO;
use Pdo\Pgsql;
use PDOException;

class WorkMailForwards extends Command
{
    public const CHANNEL = 'mailmm_forwarding_jobs';

    public const CONTROL = 'control';

    protected $signature = 'mail:work-forwards';

    protected $description = 'Process forwarding jobs as PostgreSQL makes them available';

    public function handle(QueueManager $queues): int
    {
        $connection = 'forwarding';
        $queue = $queues->connection($connection);
        $database = $queue->getDatabase();
        if ($database->getDriverName() !== 'pgsql') {
            $this->error('The forwarding worker requires PostgreSQL.');

            return self::FAILURE;
        }

        $worker = $this->laravel->make('queue.worker');
        $table = $database->getQueryGrammar()->wrapTable(config('queue.connections.forwarding.table'));
        $retryAfter = (int) config('queue.connections.forwarding.retry_after');
        $listening = null;
        Worker::popUsing($connection, function ($pop, $name) use ($connection, $queues, $worker, $database, $table, $retryAfter, &$listening) {
            try {
                $database->reconnectIfMissingConnection();
                $pdo = $database->getPdo();
                if ($pdo !== $listening) {
                    $pdo->exec('LISTEN '.self::CHANNEL);
                    $listening = $pdo;

                    // Recheck Laravel's restart marker after subscribing to close the startup or reconnect gap.
                    return null;
                }
                while (($notice = $pdo->getNotify(PDO::FETCH_NUM, 0)) !== false) {
                    if (($notice[2] ?? null) === self::CONTROL) {
                        return null;
                    }
                }
                if ($worker->shouldQuit || $worker->paused) {
                    return null;
                }

                $paused = Worker::$pausable && $queues->isPaused($connection, $name);
                $next = $paused ? null : $database->selectOne(<<<SQL
                    select least(
                        (select min(available_at) from {$table} where queue = ? and reserved_at is null),
                        (select min(reserved_at) from {$table} where queue = ? and reserved_at is not null) + ?
                    ) as ready_at
                    SQL, [$name, $name, $retryAfter], false)->ready_at;
                if ($database->getPdo() !== $pdo) {
                    return null;
                }
                if ($next !== null && $next <= now()->timestamp && ($job = $pop($name))) {
                    return $job;
                }

                if ($database->getPdo() === $pdo) {
                    $this->waitForWork($pdo, $worker, $next);
                }
            } catch (PDOException $failure) {
                $listening = null;
                $database->disconnect();

                throw $failure;
            }

            // Let Laravel observe restart and pause signals before trying to reserve work again.
            return null;
        });

        try {
            return $this->call('queue:work', [
                'connection' => $connection, '--name' => $connection, '--queue' => $queue->getQueue(null),
                '--sleep' => 0, '--timeout' => 60,
            ]);
        } finally {
            Worker::popUsing($connection, null);
        }
    }

    private function waitForWork(Pgsql $pdo, Worker $worker, ?int $deadline): void
    {
        $until = $deadline;
        if ($until !== null && $until <= microtime(true)) {
            // An eligible row can be locked by another PostgreSQL worker.
            $until = microtime(true) + 0.25;
        }

        while (! $worker->shouldQuit && ! $worker->paused) {
            // Bound the socket wait for process signals without polling PostgreSQL.
            $milliseconds = $until === null ? 1000 : (int) ceil(($until - microtime(true)) * 1000);
            if ($milliseconds <= 0 || $pdo->getNotify(PDO::FETCH_NUM, min($milliseconds, 1000)) !== false) {
                return;
            }
        }
    }
}
