<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MailboxPageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\AttachmentDownload;
use App\Services\MailboxInbox;
use App\Services\MailboxSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    public function __construct(private readonly MailboxSession $mailboxes) {}

    public function index(Request $request, MailboxInbox $inbox): JsonResponse
    {
        $email = $this->mailboxes->current($request);
        // Recover the guest session even when the requested page is invalid.
        $cursor = Cursor::fromEncoded(app(MailboxPageRequest::class)->validated('cursor'));

        $since = $request->header('X-Inbox-Since');
        $through = $request->header('X-Inbox-Until');
        $snapshot = $email && is_string($since) && strlen($since) <= 100 && ($cursor === null || $through !== null)
            ? ($through ?? $inbox->snapshot($email)) : null;
        $delta = is_string($snapshot) && strlen($snapshot) <= 100 ? $inbox->delta($email, $since, $snapshot, $cursor) : null;
        abort_if($through !== null && $delta === null, 422);
        $response = response()->json($delta ?? $inbox->page($email, $cursor))
            ->header('X-Mailbox-Id', (string) ($email?->id ?? 0));

        return $snapshot === null ? $response : $response->header('X-Inbox-Snapshot', $snapshot);
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
