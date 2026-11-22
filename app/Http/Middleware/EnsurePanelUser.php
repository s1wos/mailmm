<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = $request->header('X-Panel-User');
        if (! $request->isMethodSafe() && $expected !== null && $expected !== (string) $request->user()->id) {
            return response()->json(['code' => 'panel_user_changed'], 409);
        }

        return $next($request);
    }
}
