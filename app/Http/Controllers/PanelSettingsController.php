<?php

namespace App\Http\Controllers;

use App\Events\MailboxAccessRevoked;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateGuestIssuanceRequest;
use App\Http\Responses\PanelResponse;
use App\Models\MailboxDomain;
use App\Models\MailboxSettings;
use App\Models\User;
use App\Services\MailboxNotifications;
use App\Services\MailboxSocketGrant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PanelSettingsController extends Controller
{
    public function show(Request $request): View|JsonResponse
    {
        return PanelResponse::screen($request, 'settings', fn () => [
            'mxHost' => config('mailbox.mx_host'),
            'guestIssuanceEnabled' => MailboxSettings::current()->guest_issuance_enabled,
            'domains' => MailboxDomain::forSettings(),
        ]);
    }

    public function issuance(UpdateGuestIssuanceRequest $request): JsonResponse|RedirectResponse
    {
        $settings = MailboxSettings::current();
        $settings->guest_issuance_enabled = $request->boolean('guest_issuance_enabled');
        $settings->save();

        return PanelResponse::redirect($request, route('panel.settings', absolute: false), 'Настройки выдачи адресов сохранены.');
    }

    public function password(ChangePasswordRequest $request): JsonResponse|RedirectResponse
    {
        $this->changePassword($request, admin: true);

        return PanelResponse::redirect($request, route('panel.settings', absolute: false), 'Пароль администратора изменён.');
    }

    public function mailboxPassword(ChangePasswordRequest $request): JsonResponse|RedirectResponse
    {
        $this->changePassword($request, admin: false);

        return PanelResponse::redirect($request, route('panel.index', absolute: false), 'Пароль ящика изменён.');
    }

    private function changePassword(ChangePasswordRequest $request, bool $admin): void
    {
        $user = DB::transaction(function () use ($request, $admin) {
            $user = User::where('is_admin', $admin)->lockForUpdate()->find($request->user()->id);
            abort_unless($user, 403);
            $mailbox = null;
            if (! $admin) {
                $mailbox = $user->mailbox()->activePermanent()->lockForUpdate()->first(['id']);
                abort_unless($mailbox, 403);
            }

            if (! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
            }
            $user->password = Hash::make($request->validated('password'));
            $user->setRememberToken(Str::random(60));
            $user->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)
                    ->where('id', '!=', $request->session()->getId())->delete();
            }
            if ($mailbox) {
                MailboxNotifications::publish(new MailboxAccessRevoked($mailbox->id));
            }

            return $user;
        });
        Auth::setUser($user);
        MailboxSocketGrant::revokeSession($request);
        $request->session()->regenerate(true);
    }
}
