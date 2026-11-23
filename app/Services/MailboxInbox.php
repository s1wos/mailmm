<?php

namespace App\Services;

use App\Models\Email;
use App\Models\Message;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;

class MailboxInbox
{
    public function snapshot(Email $mailbox): string
    {
        $inbox = $mailbox->messages()->retained()->toBase()
            ->selectRaw('COUNT(*) AS total, MIN(received_at) AS oldest')->first();

        return $inbox->total.'/'.$inbox->oldest;
    }

    public function page(?Email $mailbox, ?Cursor $cursor = null, bool $socketPreview = false): array
    {
        $query = $mailbox?->messages()->retained()->latest('received_at')->latest('id')->toBase()
            ->select(['id', 'received_at'])
            ->selectRaw('COALESCE(json_array_length(attachments), 0) > 0 AS has_attachments');
        foreach (['from', 'subject'] as $field) {
            if ($socketPreview) {
                $column = '"'.$field.'"';
                $query?->selectRaw("CASE WHEN substr($column, 2049, 1) <> '' THEN substr($column, 1, 2047) || '…' ELSE $column END AS $column");
            } else {
                $query?->addSelect($field);
            }
        }
        $page = $query?->cursorPaginate(30, cursor: $cursor);
        $headers = $page?->getCollection()->map(function ($row) {
            $receivedAt = Carbon::parse($row->received_at);

            return [
                'id' => (int) $row->id,
                'from' => $row->from,
                'subject' => $row->subject,
                'received_at' => $receivedAt->toISOString(),
                'expires_at' => Message::expiresAt($receivedAt)->toISOString(),
                'has_attachments' => (bool) $row->has_attachments,
            ];
        })->all();

        return ['data' => $headers ?? [], 'meta' => ['next_cursor' => $page?->nextCursor()?->encode()]];
    }
}
