<?php

namespace App\Http\Controllers;

use App\Http\Responses\PanelResponse;
use App\Models\User;
use App\Services\MailboxSocketGrant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PanelLoginController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        return response()->json(['user' => PanelResponse::user($request)]);
    }

    public function show(Request $request): View|JsonResponse|RedirectResponse
    {
        return $request->user()
            ? PanelResponse::redirect($request, route('panel.index', absolute: false))
            : PanelResponse::screen($request, 'login');
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $index = route('panel.index', absolute: false);
        if ($request->user()) {
            return PanelResponse::redirect($request, $index);
        }
        $credentials = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string']]);
        $credentials['email'] = Str::lower($credentials['email']);
        if (! Auth::attemptWhen($credentials, function (User $user) use ($request) {
            if (! $user->is_admin && ! $user->mailbox()->active()->exists()) {
                return false;
            }
            MailboxSocketGrant::revokeSession($request);

            return true;
        })) {
            throw ValidationException::withMessages(['email' => 'Проверьте логин и пароль.']);
        }

        $intended = $request->session()->pull('url.intended', '');
        $path = Str::after($intended, $request->root());
        if (! preg_match('~^/admin(?:/|\?|$)~', $path)) {
            $path = $index;
        }

        return PanelResponse::redirect($request, $path);
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        $guestMailbox = $request->session()->only(['email_id', 'guest_mailbox_key']);
        MailboxSocketGrant::revokeSession($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put($guestMailbox);

        return PanelResponse::redirect($request, route('login', absolute: false));
    }
}
