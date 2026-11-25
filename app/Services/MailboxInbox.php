<?php

namespace App\Services;

use App\Models\Email;
use App\Models\Message;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MailboxInbox
{
    private const PAGE_SIZE = 30;

    public function snapshot(Email $mailbox): string
    {
        $oldest = $mailbox->messages()->retained()->toBase()
            ->orderBy('received_at')->limit(1)->select('received_at');
        $inbox = DB::table('mailbox_inbox_states')->where('email_id', $mailbox->id)
            ->select(['revision', 'reset_revision'])->selectSub($oldest, 'oldest')->first();

        return 'v1/'.$mailbox->id.'/'.($inbox->revision ?? 0).'/'.($inbox->reset_revision ?? 0).'/'.($inbox->oldest ?? '-');
    }

    public function delta(Email $mailbox, string $since, string $snapshot, ?Cursor $cursor = null, bool $socketPreview = false): ?array
    {
        $from = explode('/', $since);
        $through = explode('/', $snapshot);
        if (count($from) !== 5 || count($through) !== 5 || $from[0] !== 'v1' || $through[0] !== 'v1'
            || $from[1] !== (string) $mailbox->id || $through[1] !== $from[1] || $from[3] !== $through[3]
            || ! preg_match('/\A(?:-|\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,6})?)\z/', $through[4])) {
            return null;
        }
        $reset = filter_var($through[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $latest = filter_var($through[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($reset === false || $latest === false) {
            return null;
        }
        $revision = filter_var($from[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => $reset, 'max_range' => $latest]]);
        $position = $cursor?->parameter('inbox_revision');
        if ($revision === false || ($position !== null && ($position <= $revision || $position > $latest))) {
            return null;
        }
        $page = $this->headers($mailbox, $socketPreview)->addSelect('inbox_revision')
            ->where('inbox_revision', '>', $revision)->where('inbox_revision', '<=', $latest)
            ->orderBy('inbox_revision')->cursorPaginate(self::PAGE_SIZE, cursor: $cursor);

        return [
            'data' => $page->getCollection()->map($this->summary(...))->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'delta' => true],
        ];
    }

    public function page(?Email $mailbox, ?Cursor $cursor = null, bool $socketPreview = false): array
    {
        $page = $mailbox ? $this->headers($mailbox, $socketPreview)->latest('received_at')->latest('id')
            ->cursorPaginate(self::PAGE_SIZE, cursor: $cursor) : null;
        $rows = $page?->getCollection()->map($this->summary(...))->all();

        return ['data' => $rows ?? [], 'meta' => ['next_cursor' => $page?->nextCursor()?->encode()]];
    }

    private function headers(Email $mailbox, bool $socketPreview): Builder
    {
        $query = $mailbox->messages()->retained()->toBase()
            ->select(['id', 'received_at'])
            ->selectRaw('COALESCE(json_array_length(attachments), 0) > 0 AS has_attachments');
        foreach (['from', 'subject'] as $field) {
            if ($socketPreview) {
                $column = '"'.$field.'"';
                $query->selectRaw("CASE WHEN substr($column, 2049, 1) <> '' THEN substr($column, 1, 2047) || '…' ELSE $column END AS $column");
            } else {
                $query->addSelect($field);
            }
        }

        return $query;
    }

    private function summary(object $row): array
    {
        $receivedAt = Carbon::parse($row->received_at);

        return [
            'id' => (int) $row->id,
            'from' => $row->from,
            'subject' => $row->subject,
            'received_at' => $receivedAt->toISOString(),
            'expires_at' => Message::expiresAt($receivedAt)->toISOString(),
            'has_attachments' => (bool) $row->has_attachments,
        ];
    }
}
