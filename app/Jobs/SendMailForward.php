<?php

namespace App\Jobs;

use App\Services\ForwardingDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMailForward implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public readonly string $forwardId)
    {
        $this->onConnection('forwarding')->onQueue('forwarding');
    }

    public function displayName(): string
    {
        return self::class.':'.$this->forwardId;
    }

    public function handle(ForwardingDelivery $delivery): void
    {
        $delivery->send($this->forwardId);
    }
}
