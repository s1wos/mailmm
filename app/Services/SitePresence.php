<?php

namespace App\Services;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cookie;
use Pusher\PusherException;

class SitePresence
{
    private const CHANNEL_PREFIX = 'site-visitor.';

    public const PRIVATE_PREFIX = 'private-'.self::CHANNEL_PREFIX;

    public static function browserChannel(Request $request): ?string
    {
        $id = $request->cookie(config('session.cookie').'_visitor');

        return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/', $id) ? self::CHANNEL_PREFIX.$id : null;
    }

    public static function pageChannel(Request $request): ?string
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }
        if ($channel = self::browserChannel($request)) {
            return $channel;
        }
        // Concurrent page loads with an existing login session get the same initial visitor ID.
        $id = substr(hash_hmac('sha256', 'site-visitor:'.$request->session()->getId(), config('app.key')), 0, 32);
        $cookie = Cookie::make(config('session.cookie').'_visitor', $id);
        Cookie::queue($cookie->withPartitioned(config('session.partitioned')));

        return self::CHANNEL_PREFIX.$id;
    }

    public static function activeUsers(): ?int
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }
        $config = config('broadcasting.connections.reverb');
        if (empty($config['key']) || empty($config['secret']) || empty($config['app_id'])) {
            return null;
        }
        $config['options']['timeout'] = 2;
        $config['client_options']['connect_timeout'] = 1;
        try {
            $result = Broadcast::pusher($config)->get('/channels', ['filter_by_prefix' => self::PRIVATE_PREFIX], true);
        } catch (GuzzleException|PusherException) {
            return null;
        }

        return is_array($result['channels'] ?? null) ? count($result['channels']) : null;
    }
}
