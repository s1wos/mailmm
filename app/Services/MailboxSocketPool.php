<?php

namespace App\Services;

use Closure;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class MailboxSocketPool
{
    private const WORKERS = 4;

    private const QUEUE = 12;

    private const TIMEOUT = 8;

    private array $waiting = [];

    private array $running = [];

    private ?TimerInterface $timer = null;

    public function submit(array $job, Closure $complete): void
    {
        if (count($this->waiting) >= self::QUEUE) {
            $complete(['code' => 'busy']);

            return;
        }
        $this->waiting[] = [$job, $complete, microtime(true) + self::TIMEOUT];
        $this->timer ??= Loop::addPeriodicTimer(0.02, $this->advance(...));
    }

    private function advance(): void
    {
        foreach ($this->running as $index => [$process, $complete]) {
            try {
                $process->checkTimeout();
                if ($process->isRunning()) {
                    continue;
                }
                $output = $process->getOutput();
                $result = $process->isSuccessful() && strlen($output) <= 1048576
                    ? json_decode($output, true, flags: JSON_THROW_ON_ERROR) : null;
            } catch (ProcessTimedOutException) {
                $result = ['code' => 'timeout'];
            } catch (Throwable) {
                $result = null;
            }
            unset($this->running[$index]);
            $complete(is_array($result) ? $result : ['code' => 'unavailable']);
        }
        while ($this->waiting && count($this->running) < self::WORKERS) {
            [$job, $complete, $deadline] = array_shift($this->waiting);
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $complete(['code' => 'busy']);

                continue;
            }
            try {
                $process = new Process([PHP_BINARY, base_path('artisan'), 'mail:socket-read', '--no-ansi'], base_path());
                $process->setInput(json_encode($job, JSON_THROW_ON_ERROR));
                $process->setTimeout($remaining);
                $process->start();
                $this->running[] = [$process, $complete];
            } catch (Throwable) {
                $complete(['code' => 'unavailable']);
            }
        }
        if (! $this->running && ! $this->waiting) {
            Loop::cancelTimer($this->timer);
            $this->timer = null;
        }
    }
}
