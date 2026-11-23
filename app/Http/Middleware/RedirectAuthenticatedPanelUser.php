<?php

namespace App\Http\Middleware;

use App\Http\Responses\PanelResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectAuthenticatedPanelUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return PanelResponse::redirect($request, route('panel.index', absolute: false));
        }

        return $next($request);
    }
}
