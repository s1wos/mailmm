<?php

namespace App\Http\Controllers;

use App\Services\MailboxSession;
use App\Services\MailImageUrl;
use App\Services\PanelMessages;
use App\Services\RemoteMailImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

final class MailImageController
{
    public function __invoke(Request $request, int $message, string $token, MailboxSession $mailboxes, RemoteMailImage $remote): Response
    {
        $url = MailImageUrl::destination($message, $token);
        abort_if($url === null, 404);
        $user = $request->user();
        $letters = $user ? PanelMessages::for($user) : $mailboxes->current($request)?->messages()->retained();
        abort_unless($letters?->whereKey($message)->exists(), 404);

        $digest = hash('sha256', $url);
        // Fixed slots bound disk use even when senders generate unique tracking URLs.
        $cache = Cache::store('file');
        $slot = 'mail-image:'.(hexdec(substr($digest, 0, 2)) % 64);
        $entry = $cache->get($slot);
        if (($entry['digest'] ?? null) === $digest) {
            $image = $entry['image'];
        } else {
            try {
                $image = $cache->lock($slot.':fill', 8)->block(6, function () use ($cache, $slot, $digest, $remote, $url) {
                    $entry = $cache->get($slot);
                    if (($entry['digest'] ?? null) === $digest) {
                        return $entry['image'];
                    }
                    $image = $remote->fetch($url);
                    $cache->put($slot, ['digest' => $digest, 'image' => $image], $image === null ? 60 : 86400);

                    return $image;
                });
            } catch (LockTimeoutException) {
                abort(503, headers: ['Retry-After' => '1']);
            }
        }
        abort_if($image === null, 404);

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
