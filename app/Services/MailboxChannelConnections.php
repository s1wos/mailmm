<?php

namespace App\Services;

use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Laravel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;

class MailboxChannelConnections extends ArrayChannelConnectionManager
{
    public function add(Connection $connection, array $data): void
    {
        if (preg_match('/\Aprivate-mailbox\.[1-9]\d*\z/', $this->name)) {
            $this->remove($connection);
            $this->connections[$connection->id()] = new MailboxChannelMember($connection, $data, $this->name);

            return;
        }
        parent::add($connection, $data);
    }

    public function findById(string $id): ?ChannelConnection
    {
        $member = parent::findById($id);

        return $member instanceof MailboxChannelMember && $member->awaitingRevocation() ? null : $member;
    }

    public function remove(Connection $connection): void
    {
        $member = parent::findById($connection->id());
        if ($member instanceof MailboxChannelMember) {
            $member->discardPendingWork();
        }
        parent::remove($connection);
    }
}
