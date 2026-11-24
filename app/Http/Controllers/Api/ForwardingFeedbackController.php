<?php

namespace App\Http\Controllers\Api;

use App\Services\ForwardingFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class ForwardingFeedbackController
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('forwarding.webhook_secret');
        abort_if($secret === '', 404);
        $stamp = (string) $request->header('X-MailMM-Timestamp');
        $body = $request->getContent();
        abort_if(strlen($body) > 4096, 413);
        abort_unless(preg_match('/^\d{10}$/D', $stamp) && abs(now()->timestamp - (int) $stamp) <= 300
            && hash_equals(hash_hmac('sha256', $stamp.'.'.$body, $secret), (string) $request->header('X-MailMM-Signature')), 403);
        $data = json_decode($body, true);
        $id = $data['operation_id'] ?? null;
        $reason = $data['event'] ?? null;
        abort_unless(is_string($id) && Str::isUuid($id) && in_array($reason, ['permanent_bounce', 'complaint'], true), 422);
        ForwardingFeedback::record($id, $reason);

        return response()->noContent();
    }
}
