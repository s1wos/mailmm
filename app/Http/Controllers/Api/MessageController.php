<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\AttachmentDownload;
use App\Services\MailboxInbox;
use App\Services\MailboxSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    public function __construct(private readonly MailboxSession $mailboxes) {}

    public function index(Request $request, MailboxInbox $inbox): JsonResponse
    {
        $email = $this->mailboxes->current($request);
        $request->validate(['cursor' => ['sometimes', 'required', 'string']]);

        return response()->json($inbox->page($email, $request->query('cursor')))
            ->header('X-Mailbox-Id', (string) ($email?->id ?? 0));
    }

    public function show(Request $request, int $message): JsonResponse
    {
        $letter = $this->message($request, $message);

        return (new MessageResource($letter))->response()->header('X-Mailbox-Id', (string) $letter->email_id);
    }

    public function attachment(Request $request, int $message, int $index, AttachmentDownload $download): Response
    {
        return $download->forMessage($request, $this->message($request, $message), $index);
    }

    private function message(Request $request, int $id): Message
    {
        $email = $this->mailboxes->current($request);
        abort_unless($email, 404);

        return $email->messages()->retained()->findOrFail($id);
    }
}
