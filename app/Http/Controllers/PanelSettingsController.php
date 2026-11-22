<?php

namespace App\Http\Controllers;

use App\Services\MailboxSocketGrant;
use App\Http\Responses\PanelResponse;
use App\Models\MailboxDomain;
use App\Models\MailboxSettings;
use App\Models\User;
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
            'domains' => MailboxDomain::orderBy('name')->get(['id', 'name', 'issuance_enabled']),
        ]);
    }

    public function issuance(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['guest_issuance_enabled' => ['required', 'boolean']]);
        $settings = MailboxSettings::current();
        $settings->guest_issuance_enabled = $request->boolean('guest_issuance_enabled');
        $settings->save();

        return PanelResponse::redirect($request, route('panel.settings', absolute: false), 'Настройки выдачи адресов сохранены.');
    }

    public function password(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'regex:/\A.{1,72}\z/sD', 'confirmed'],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'password.required' => 'Введите новый пароль.',
            'password.min' => 'Новый пароль должен содержать минимум 12 символов.',
            'password.regex' => 'Пароль должен занимать не более 72 байт.',
            'password.confirmed' => 'Подтверждение не совпадает с новым паролем.',
        ]);
        $admin = DB::transaction(function () use ($user, $data, $request) {
            $admin = User::where('is_admin', true)->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($data['current_password'], $admin->password)) {
                throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
            }
            $admin->password = Hash::make($data['password']);
            $admin->setRememberToken(Str::random(60));
            $admin->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $admin->id)
                    ->where('id', '!=', $request->session()->getId())->delete();
            }

            return $admin;
        });
        Auth::setUser($admin);
        MailboxSocketGrant::revokeSession($request);
        $request->session()->regenerate(true);

        return PanelResponse::redirect($request, route('panel.settings', absolute: false), 'Пароль администратора изменён.');
    }
}
