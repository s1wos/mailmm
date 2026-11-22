<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddPanelUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Mailmm-Is-Admin', $request->user()?->is_admin ? '1' : '0');
        if ($request->user() !== null) {
            $response->headers->set('X-Panel-Url', route('panel.index', absolute: false));
        }

        return $response;
    }
}
