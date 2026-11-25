<?php

namespace App\Services;

use Closure;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Throwable;

class MailboxSocketPool
{
    private const WORKERS = 4;

    private const QUEUE = 1024;

    private const TIMEOUT = 8;

    private array $waiting = [];

    private array $running = [];

    /** @var list<MailboxSocketWorker> */
    private array $idle = [];

    private ?TimerInterface $timer = null;

    public function submit(array $job, Closure $complete): void
    {
        if (count($this->waiting) >= self::QUEUE) {
            $complete(['code' => 'busy']);

            return;
        }
        $this->waiting[] = [$job, $complete, hrtime(true) / 1e9 + self::TIMEOUT];
        $this->timer ??= Loop::addPeriodicTimer(0.01, $this->advance(...));
    }

    private function advance(): void
    {
        foreach ($this->running as $index => [$worker, $complete, $deadline]) {
            try {
                if ($deadline <= hrtime(true) / 1e9) {
                    $worker->close();
                    $result = ['code' => 'timeout'];
                } else {
                    $result = $worker->read();
                    if ($result === null) {
                        continue;
                    }
                    $this->idle[] = $worker;
                }
            } catch (Throwable) {
                $worker->close();
                $result = ['code' => 'unavailable'];
            }
            unset($this->running[$index]);
            $complete($result);
        }
        while ($this->waiting && count($this->running) < self::WORKERS) {
            [$job, $complete, $deadline] = array_shift($this->waiting);
            if ($deadline <= hrtime(true) / 1e9) {
                $complete(['code' => 'busy']);

                continue;
            }
            $worker = null;
            try {
                $input = json_encode($job, JSON_THROW_ON_ERROR);
                $worker = $this->takeWorker();
                $worker->send($input);
                $this->running[] = [$worker, $complete, $deadline];
            } catch (Throwable) {
                $worker?->close();
                $complete(['code' => 'unavailable']);
            }
        }
        if (! $this->running && ! $this->waiting) {
            Loop::cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    private function takeWorker(): MailboxSocketWorker
    {
        while ($worker = array_pop($this->idle)) {
            if ($worker->isRunning()) {
                return $worker;
            }
            $worker->close();
        }

        return new MailboxSocketWorker;
    }
}
