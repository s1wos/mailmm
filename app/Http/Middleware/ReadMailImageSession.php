<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\StartSession;

final class ReadMailImageSession extends StartSession
{
    public function handle($request, Closure $next)
    {
        // Read the existing identity without holding a session lock or issuing cookies
        // during a potentially slow request to an external image host.
        $request->setLaravelSession($this->startSession($request, $this->getSession($request)));

        return $next($request);
    }
}
