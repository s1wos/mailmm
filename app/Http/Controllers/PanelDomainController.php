<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailboxDomainRequest;
use App\Http\Requests\UpdateMailboxDomainRequest;
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
    public function store(StoreMailboxDomainRequest $request): JsonResponse|RedirectResponse
    {
        try {
            DB::transaction(fn () => MailboxDomain::create($request->validated()));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Этот домен уже подключён.']);
        }

        return $this->saved($request, 'Домен добавлен. Проверьте доставку письма в архив перед включением выдачи.');
    }

    public function update(UpdateMailboxDomainRequest $request, int $domain): JsonResponse|RedirectResponse
    {
        $updated = MailboxDomain::whereKey($domain)->update(['issuance_enabled' => $request->boolean('issuance_enabled')]);
        abort_unless($updated, 404);

        return $this->saved($request, 'Режим домена сохранён.');
    }

    private function saved(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return PanelResponse::redirect($request, route('panel.settings', absolute: false), $message, fn () => [
            'domains' => MailboxDomain::forSettings(),
        ]);
    }
}
