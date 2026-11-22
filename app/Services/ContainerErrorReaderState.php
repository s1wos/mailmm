<?php

namespace App\Services;

final class ContainerErrorReaderState
{
    private ?bool $ready = null;
    private bool $writeCompleted = false;

    public function beginProbe(): void
    {
        $this->writeCompleted = false;
    }

    public function receive(string $signals): void
    {
        $writeSignals = $this->ready === null ? substr($signals, 1) : $signals;
        $this->writeCompleted = $this->writeCompleted || str_contains($writeSignals, '+');
        $this->ready = str_ends_with($signals, '+');
    }

    public function healthy(): bool
    {
        return $this->ready === true || $this->writeCompleted;
    }
}
