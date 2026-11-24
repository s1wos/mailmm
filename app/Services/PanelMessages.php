<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PanelMessages
{
    public static function for(User $user): Builder
    {
        $query = Message::retained();
        if (! $user->is_admin) {
            $query->whereIn('email_id', $user->mailbox()->activePermanent()->select('id'));
        }

        return $query;
    }
}
