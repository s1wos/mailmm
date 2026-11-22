<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(0)]
#[Backoff(2, 5, 15, 60, 300)]
final class MailboxAccessRevoked implements ShouldBroadcast
{
    public bool $afterCommit = false;

    public function __construct(private readonly int $mailboxId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('mailbox.'.$this->mailboxId);
    }

    public function broadcastAs(): string
    {
        return 'mailbox.access-revoked';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
