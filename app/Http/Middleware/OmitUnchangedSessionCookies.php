<?php

namespace App\Http\Middleware;

use App\Services\GuestMailboxCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OmitUnchangedSessionCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        GuestMailboxCookie::renew($request, $response);
        if (! $response instanceof JsonResponse || ! $request->hasSession()) {
            return $response;
        }

        $names = [$request->session()->getName(), 'XSRF-TOKEN'];
        foreach ($response->headers->getCookies() as $cookie) {
            $name = $cookie->getName();
            if (in_array($name, $names, true) && $cookie->getValue() !== null && $cookie->getValue() === $request->cookie($name)) {
                $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
