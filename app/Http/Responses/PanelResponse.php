<?php

namespace App\Http\Responses;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PanelResponse
{
    public static function user(Request $request): ?array
    {
        return $request->user()?->only(['id', 'email', 'is_admin']);
    }

    public static function screen(Request $request, string $screen, ?Closure $data = null): JsonResponse|View
    {
        if (! $request->wantsJson()) {
            return view('app');
        }

        return response()->json([
            'screen' => $screen,
            'user' => self::user($request),
            ...($data ? $data() : []),
        ]);
    }

    public static function redirect(Request $request, string $path, ?string $message = null): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(array_filter(['redirect' => $path, 'message' => $message]));
        }

        $response = redirect($path);

        return $message === null ? $response : $response->with('status', $message);
    }
}
