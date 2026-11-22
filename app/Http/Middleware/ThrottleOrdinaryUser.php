<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

class ThrottleOrdinaryUser extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        return $request->user()?->is_admin
            ? $next($request)
            : parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
