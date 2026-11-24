<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\Message;
use App\Services\MailboxIssuance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PruneMail extends Command
{
    protected $signature = 'mail:prune';

    protected $description = 'Delete expired messages, attachments and unused closed mailboxes';

    public function handle(): int
    {
        $cutoff = now()->subDays(Message::RETENTION_DAYS)->format('Y-m-d H:i:s.u');
        $failed = 0;
        $expired = Message::query()->where('received_at', '<=', $cutoff);
        foreach ($expired->select('id')->lazyById(200) as $row) {
            try {
                DB::transaction(function () use ($row, $cutoff) {
                    $message = Message::where('received_at', '<=', $cutoff)->lockForUpdate()->find($row->id);
                    if (! $message) {
                        return;
                    }
                    $paths = array_column($message->attachments ?? [], 'path');
                    if ($message->raw_path !== null) {
                        $paths[] = $message->raw_path;
                        $paths[] = $message->raw_path.'.writing';
                    }
                    if ($paths && ! Storage::disk('local')->delete($paths)) {
                        throw new RuntimeException("Could not remove attachments of message {$row->id}.");
                    }
                    $message->delete();
                });
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        Email::query()->where(fn ($q) => $q->where('closed_at', '<=', $cutoff)->orWhere('expires_at', '<=', $cutoff))
            ->whereDoesntHave('messages')->delete();

        MailboxIssuance::eraseExpiredPasswords();

        if ($failed) {
            $this->error("Cleanup will retry {$failed} message(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
