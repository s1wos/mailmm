<?php

namespace App\Http\Controllers\Api;

use App\Services\MailboxSession;
use App\Services\MailboxSocketGrant;
use App\Services\SitePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class MailboxChannelController
{
    public function __invoke(Request $request, MailboxSession $mailboxes): JsonResponse
    {
        $input = $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A[0-9]+\.[0-9]+\z/'],
            'channel_name' => ['required', 'string', 'max:64'],
        ]);
        if (str_starts_with($input['channel_name'], SitePresence::PRIVATE_PREFIX)) {
            $existing = SitePresence::browserChannel($request);
            if ($existing === null || $input['channel_name'] !== 'private-'.$existing) {
                $channel = $existing ?? SitePresence::pageChannel($request);
                abort_unless($channel !== null, 403);

                return response()->json(['visitorChannel' => $channel], 403);
            }
            $authorization = Broadcast::connection('reverb')->getPusher()->authorizeChannel($input['channel_name'], $input['socket_id']);

            return response()->json(json_decode($authorization, true, flags: JSON_THROW_ON_ERROR));
        }
        $mailbox = $mailboxes->current($request);
        abort_unless($mailbox && $input['channel_name'] === 'private-mailbox.'.$mailbox->id, 403);

        $pusher = Broadcast::connection('reverb')->getPusher();

        $binding = MailboxSocketGrant::issue($request, $mailbox, $input['socket_id']);
        $authorization = $pusher->authorizeChannel($input['channel_name'], $input['socket_id'], json_encode($binding, JSON_THROW_ON_ERROR));

        return response()->json([...json_decode($authorization, true, flags: JSON_THROW_ON_ERROR), 'grantExpiresAt' => $binding['grantExpiresAt']]);
    }
}
