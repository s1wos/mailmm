<?php

namespace App\Http\Controllers;

use App\Models\MailForward;
use App\Rules\ForwardingAddress;
use App\Services\ForwardingRequests;
use App\Services\ForwardingRules;
use App\Services\PanelMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ForwardingController
{
    private function destination(Request $request): string
    {
        $address = ForwardingAddress::normalize($request->input('to'));
        if ($address === null) {
            throw ValidationException::withMessages(['to' => 'Введите один почтовый адрес, например name@example.org.']);
        }

        return $address;
    }

    private function assertEnabled(): void
    {
        abort_if(ForwardingRequests::automaticSince() === null, 403, 'Автопересылка отключена.');
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => ['required', 'integer', 'min:1']],
            ['version.*' => 'Обновите настройки пересылки.'])['version'];
    }

    public function register(Request $request): JsonResponse
    {
        $password = $request->validate(['current_password' => ['required', 'string']],
            ['current_password.*' => 'Введите текущий пароль.'])['current_password'];
        $rule = ForwardingRules::register($request->user(), $this->destination($request), $password);

        return response()->json(['forwarding' => $rule->panelData()]);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $code = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']],
            ['code.*' => 'Введите шестизначный код.'])['code'];
        $rule = ForwardingRules::verify($request->user(), $this->version($request), $code);

        return response()->json(['forwarding' => $rule->panelData()]);
    }

    public function resend(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $rule = ForwardingRules::resend($request->user(), $this->version($request));

        return response()->json(['forwarding' => $rule->panelData()]);
    }

    public function disable(Request $request): JsonResponse
    {
        $rule = ForwardingRules::disable($request->user(), $this->version($request));

        return response()->json(['forwarding' => $rule->panelData()]);
    }

    public function send(Request $request, int $message): JsonResponse
    {
        PanelMessages::for($request->user())->findOrFail($message, ['id']);
        $id = $request->validate(['request_id' => ['required', 'string', 'uuid']],
            ['request_id.*' => 'Обновите страницу и повторите отправку.'])['request_id'];
        $operation = ForwardingRequests::manual($request->user(), $message, $this->destination($request), strtolower($id));

        return response()->json(['forward' => $operation->panelData()], 202);
    }

    public function status(Request $request, int $message, string $forward): JsonResponse
    {
        PanelMessages::for($request->user())->findOrFail($message, ['id']);
        $operation = MailForward::where('message_id', $message)->visibleTo($request->user())->findOrFail($forward);

        return response()->json(['forward' => $operation->panelData()]);
    }
}
