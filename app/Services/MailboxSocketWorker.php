<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class MailboxSocketWorker
{
    private const MAX_BYTES = 1048576;

    /** @var resource|Process|null */
    private mixed $process = null;

    /** @var resource[] */
    private array $pipes = [];

    private ?InputStream $input = null;

    private string $pending = '';

    private string $response = '';

    public function __construct()
    {
        $command = [PHP_BINARY, base_path('artisan'), 'mail:socket-read', '--worker', '--no-ansi'];
        if (PHP_OS_FAMILY === 'Windows') {
            $this->input = new InputStream;
            $this->process = new Process($command, base_path(), input: $this->input, timeout: null);
            $this->process->start();

            return;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            // An idle child must not keep inherited Reverb sockets open.
            $command = ['/bin/bash', '-c', 'for path in /proc/$$/fd/*; do fd=${path##*/}; if ((fd > 2)); then exec {fd}>&-; fi; done; exec "$@"',
                'mailbox-socket-worker', ...$command];
        }

        // Symfony's Unix pipes use stream_select, even when Reverb runs on libuv.
        $this->process = proc_open(
            $command, [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'a']],
            $this->pipes, base_path(), $_ENV + getenv(),
        );
        if (! is_resource($this->process) || ! stream_set_blocking($this->pipes[0], false)
            || ! stream_set_blocking($this->pipes[1], false)) {
            $this->close();

            throw new RuntimeException('Could not start a mailbox socket worker.');
        }
    }

    public function send(string $job): void
    {
        $this->response = '';
        if ($this->input) {
            $this->input->write($job."\n");
        } else {
            $this->pending = $job."\n";
            $this->writePending();
        }
    }

    public function read(): ?array
    {
        $this->response .= $this->readOutput();
        $length = strlen($this->response);
        $newline = strpos($this->response, "\n");
        if ($length > self::MAX_BYTES + 1 || ($newline !== false && $newline !== $length - 1)) {
            throw new RuntimeException('Invalid mailbox socket worker response frame.');
        }
        if ($newline !== false) {
            $result = json_decode($this->response, true, flags: JSON_THROW_ON_ERROR);
            $this->response = '';
            if (! is_array($result)) {
                throw new RuntimeException('Invalid mailbox socket worker response.');
            }

            return $result;
        }
        if (! $this->isRunning()) {
            throw new RuntimeException('Mailbox socket worker exited before replying.');
        }

        return null;
    }

    private function readOutput(): string
    {
        if ($this->process instanceof Process) {
            $output = $this->process->getIncrementalOutput();
            $this->process->clearOutput()->clearErrorOutput();

            return $output;
        }

        $this->writePending();
        $output = stream_get_contents($this->pipes[1], self::MAX_BYTES + 2 - strlen($this->response));
        if ($output === false) {
            throw new RuntimeException('Could not read a mailbox socket worker response.');
        }

        return $output;
    }

    private function writePending(): void
    {
        if ($this->pending === '') {
            return;
        }
        $written = @fwrite($this->pipes[0], $this->pending);
        if ($written === false) {
            throw new RuntimeException('Could not write a mailbox socket worker request.');
        }
        $this->pending = substr($this->pending, $written);
    }

    public function isRunning(): bool
    {
        if ($this->process instanceof Process) {
            return $this->process->isRunning();
        }

        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function close(): void
    {
        if ($this->process instanceof Process) {
            $this->process->stop(0);
        } elseif (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
