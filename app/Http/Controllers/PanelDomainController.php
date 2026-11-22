<?php

namespace App\Http\Controllers;

use App\Http\Responses\PanelResponse;
use App\Models\MailboxDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PanelDomainController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string']], ['name.required' => 'Введите почтовый домен.']);
        try {
            DB::transaction(fn () => MailboxDomain::create(['name' => $data['name']]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Этот домен уже подключён.']);
        }

        return PanelResponse::redirect($request, route('panel.settings', absolute: false), 'Домен добавлен. Проверьте доставку письма в архив перед включением выдачи.');
    }

    public function update(Request $request, int $domain): JsonResponse|RedirectResponse
    {
        $request->validate(['issuance_enabled' => ['required', 'boolean']]);
        $updated = MailboxDomain::whereKey($domain)->update(['issuance_enabled' => $request->boolean('issuance_enabled')]);
        abort_unless($updated, 404);

        return PanelResponse::redirect($request, route('panel.settings', absolute: false), 'Режим домена сохранён.');
    }
}
