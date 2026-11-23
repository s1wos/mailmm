<?php

namespace App\Services;

use App\Models\Email;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MailboxSocketGrant
{
    public const CACHE_PREFIX = 'mailbox-ws:';

    private const MAX_SOCKETS_PER_SESSION = 16;

    public static function expiresAt(Email $mailbox): Carbon
    {
        $expiry = now()->addMinutes((int) config('session.lifetime'));

        return $mailbox->expires_at ? $expiry->min($mailbox->expires_at) : $expiry;
    }

    public static function issue(Request $request, Email $mailbox, string $socket): array
    {
        $session = $request->session();
        $actor = $request->user();
        $expiry = self::expiresAt($mailbox);
        $cache = Cache::store(config('cache.limiter'));
        $identity = [
            'session' => $session->getId(), 'csrfHash' => hash('sha256', $session->token()),
            'actor' => $actor?->id, 'passwordHash' => $actor ? hash('sha256', $actor->getAuthPassword()) : null,
            'mailbox' => $mailbox->id,
        ];
        if (config('session.driver') === 'cookie') {
            $identity['cookieSession'] = $session->only(['_token', 'email_id', 'guest_mailbox_key', Auth::guard('web')->getName()]);
            abort_if($cache->has(self::revocationKey($session->getId())), 403);
        }
        $group = hash('sha256', $session->getId());
        $key = self::CACHE_PREFIX.'session:'.$group;
        $stored = $cache->get($key);
        $bindings = ($stored['identity'] ?? null) === $identity ? $stored['bindings'] : [];
        $now = now()->toISOString();
        $bindings = array_filter($bindings, static fn (array $binding) => $binding['grantExpiresAt'] > $now);
        if (! isset($bindings[$socket])) {
            $bindings[$socket] = ['grant' => $group.'.'.bin2hex(random_bytes(32)), 'grantExpiresAt' => $expiry->toISOString()];
            $bindings = array_slice($bindings, -self::MAX_SOCKETS_PER_SESSION, preserve_keys: true);
            $cache->put($key, ['identity' => $identity, 'bindings' => $bindings], Carbon::parse(max(array_column($bindings, 'grantExpiresAt'))));
        }

        return $bindings[$socket];
    }

    public static function revokeSession(Request $request): void
    {
        $session = $request->session()->getId();
        $cache = Cache::store(config('cache.limiter'));
        $cache->forget(self::CACHE_PREFIX.'session:'.hash('sha256', $session));
        if (config('session.driver') === 'cookie') {
            $cache->put(self::revocationKey($session), true, now()->addMinutes((int) config('session.lifetime')));
        }
    }

    private static function revocationKey(string $session): string
    {
        return self::CACHE_PREFIX.'revoked:'.hash('sha256', $session);
    }

    /** @return array{Email, ?User, string, string}|null */
    public function resolve(string $token, string $socket, string $channel): ?array
    {
        $cache = Cache::store(config('cache.limiter'));
        if (str_contains($token, '.')) {
            [$group] = explode('.', $token, 2);
            $stored = $cache->get(self::CACHE_PREFIX.'session:'.$group);
            $binding = $stored['bindings'][$socket] ?? null;
            if (! $binding || ! hash_equals($binding['grant'], $token)) {
                return null;
            }
            $grant = $stored['identity'] + ['socket' => $socket, 'expiresAt' => $binding['grantExpiresAt']];
        } else {
            // Read old grants until they expire. New authorizations use session bindings.
            $grant = $cache->get(self::CACHE_PREFIX.'grant:'.hash('sha256', $token));
        }
        if (! $grant || $grant['socket'] !== $socket || $channel !== 'private-mailbox.'.$grant['mailbox']
            || $grant['expiresAt'] <= now()->toISOString()) {
            return null;
        }
        $session = clone app('session')->driver();
        $session->flush();
        $session->setId($grant['session']);
        if (isset($grant['cookieSession'])) {
            if ($cache->has(self::revocationKey($grant['session']))) {
                return null;
            }
            $session->put($grant['cookieSession']);
        } else {
            $session->start();
        }
        $actorId = $session->get(Auth::guard('web')->getName());
        if ((string) $actorId !== (string) $grant['actor']
            || ! hash_equals($grant['csrfHash'], hash('sha256', (string) $session->token()))) {
            return null;
        }
        $actor = $actorId ? User::find($actorId) : null;
        if ($actorId && (! $actor || ! hash_equals($grant['passwordHash'], hash('sha256', $actor->getAuthPassword())))) {
            return null;
        }
        $request = Request::create('/');
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn () => $actor);
        $mailbox = app(MailboxSession::class)->current($request);
        if ($mailbox?->id !== $grant['mailbox']) {
            return null;
        }

        return [$mailbox, $actor, $actor ? 'user-'.$actor->id : 'session-'.hash('sha256', $grant['session']), $grant['expiresAt']];
    }
}
