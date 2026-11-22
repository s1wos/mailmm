<?php

namespace App\Events;

use App\Models\Email;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(0)]
#[Backoff(2, 5, 15, 60, 300)]
class NewMessageReceived implements ShouldBroadcast
{
    public bool $afterCommit = false;

    public function __construct(
        private readonly int $mailboxId,
        private readonly int $messageId,
    ) {}

    public function broadcastOn(): array
    {
        if (! Email::query()->active()->whereKey($this->mailboxId)->exists()) {
            return [];
        }

        return [new PrivateChannel('mailbox.'.$this->mailboxId)];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId];
    }
}
