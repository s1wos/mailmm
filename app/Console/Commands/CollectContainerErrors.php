<?php

namespace App\Console\Commands;

use App\Services\ContainerErrorReaderState;
use App\Services\ContainerErrorStreams;
use App\Services\LogCollectionHealth;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class CollectContainerErrors extends Command
{
    protected $signature = 'logs:collect
        {--once : Import currently available records and exit}
        {--source= : Follow a single discovered container ID}
        {--report-progress : Report reader state to the supervising process on stdout}';

    protected $description = 'Store application, Postfix and nginx errors from this Compose project';

    public function handle(ContainerErrorStreams $streams): int
    {
        $sourceId = $this->option('source');
        if ($sourceId === null && ! $this->option('once')) {
            return $this->supervise($streams);
        }

        try {
            $sources = $streams->sources();
        } catch (Throwable $error) {
            if ($sourceId === null) {
                LogCollectionHealth::discoveryFailed();
            }
            return $this->failed('discovery', $error);
        }

        $status = self::SUCCESS;
        $checks = [];
        $reportReady = $sourceId !== null && $this->option('report-progress')
            ? fn (bool $ready) => $this->output->write($ready ? '+' : '-') : null;
        foreach ($sources as $id => $source) {
            if ($sourceId !== null && $sourceId !== $id) {
                continue;
            }
            try {
                $checks[$source['path']] = $streams->read($id, $source, $sourceId !== null, $reportReady)
                    && (! $source['exited'] || $source['service'] === 'migrate');
            } catch (Throwable $error) {
                $checks[$source['path']] = false;
                $status = $this->failed($source['path'], $error);
            }
        }

        if ($sourceId === null) {
            LogCollectionHealth::record($checks);
        }
        return $status;
    }

    private function supervise(ContainerErrorStreams $streams): int
    {
        $stopping = false;
        $this->trap(fn () => [SIGTERM, SIGINT], function () use (&$stopping) {
            $stopping = true;
        });
        $children = [];
        $readerStates = [];
        $drained = [];
        $missingSince = [];
        $nextCheck = 0;

        try {
            while (! $stopping) {
                try {
                    $sources = $streams->sources();
                    $drained = array_intersect_key($drained, $sources);
                    foreach ($children as $id => $process) {
                        if (isset($sources[$id])) {
                            unset($missingSince[$id]);
                        } else {
                            $missingSince[$id] ??= time();
                            if (time() - $missingSince[$id] >= 30) {
                                $process->stop(0.2);
                            }
                        }
                        if (! $process->isRunning()) {
                            if (($sources[$id]['exited'] ?? false) && $process->isSuccessful()) {
                                $drained[$id] = time();
                            }
                            unset($children[$id], $readerStates[$id], $missingSince[$id]);
                        }
                    }
                    foreach ($sources as $id => $source) {
                        if (! $source['exited']) {
                            unset($drained[$id]);
                        }
                        if (isset($children[$id]) || time() - ($drained[$id] ?? 0) < 300) {
                            continue;
                        }
                        $process = new Process([PHP_BINARY, base_path('artisan'), 'logs:collect', '--source='.$id, '--report-progress']);
                        $process->setTimeout(null)->disableOutput();
                        $state = new ContainerErrorReaderState;
                        $process->start(function ($type, $output) use ($state) {
                            if ($type === Process::ERR) {
                                $this->output->getErrorStyle()->write($output);
                            } else {
                                $state->receive($output);
                            }
                        });
                        $children[$id] = $process;
                        $readerStates[$id] = $state;
                    }
                    if (time() >= $nextCheck) {
                        foreach ($children as $id => $process) {
                            $process->isRunning();
                            $readerStates[$id]->beginProbe();
                        }
                        $checks = $streams->probe($sources);
                        usleep(250_000);
                        foreach ($sources as $id => $source) {
                            $path = $source['path'];
                            $reading = isset($children[$id]) && $children[$id]->isRunning() && $readerStates[$id]->healthy();
                            $checks[$path] = ($checks[$path] ?? false) && ($source['exited']
                                ? $source['service'] === 'migrate' && isset($drained[$id]) : $reading);
                        }
                        LogCollectionHealth::record($checks);
                        $nextCheck = time() + 10;
                    }
                } catch (Throwable $error) {
                    $this->failed('discovery', $error);
                    try {
                        LogCollectionHealth::discoveryFailed();
                    } catch (Throwable $writeError) {
                        $this->failed('heartbeat', $writeError);
                    }
                }
                if (! $stopping) {
                    sleep(3);
                }
            }
        } finally {
            foreach ($children as $process) {
                $process->stop(0.2);
            }
        }

        return self::SUCCESS;
    }

    private function failed(string $source, Throwable $error): int
    {
        $this->output->getErrorStyle()->writeln("Log collection failed ($source): ".$error::class.' ('.$error->getCode().')');

        return self::FAILURE;
    }
}
