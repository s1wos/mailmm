<?php

namespace App\Services;

use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\EventHandler;

class MailboxChannelEventHandler extends EventHandler
{
    protected function afterSubscribe(Channel $channel, Connection $connection): void
    {
        $member = $channel->find($connection);
        if ($member instanceof MailboxChannelMember) {
            $member->confirm(
                fn () => parent::afterSubscribe($channel, $connection),
                fn () => $this->sendSubscriptionError($connection, $channel->name(), 'AccessRevoked', 'Mailbox access expired'),
            );

            return;
        }
        parent::afterSubscribe($channel, $connection);
    }
}
