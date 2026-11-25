<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\Message;
use App\Services\MailboxIssuance;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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
        $expired->select('id')->chunkById(100, function ($rows) use ($expired, &$failed) {
            try {
                $failed += DB::transaction(fn () => $this->pruneBatch($expired, $rows->modelKeys()));
            } catch (Throwable $exception) {
                report($exception);
                $failed += $rows->count();
            }
        });

        Email::query()->where(fn ($q) => $q->where('closed_at', '<=', $cutoff)->orWhere('expires_at', '<=', $cutoff))
            ->whereDoesntHave('messages')->delete();

        MailboxIssuance::eraseExpiredPasswords();

        if ($failed) {
            $this->error("Cleanup will retry {$failed} message(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function pruneBatch(Builder $expired, array $ids): int
    {
        $messages = (clone $expired)->whereKey($ids)->orderBy('id')
            ->select(['id', 'email_id', 'is_pending', 'attachments', 'raw_path'])->lockForUpdate()->get();
        $deleted = $published = [];
        $failed = 0;
        foreach ($messages as $message) {
            try {
                $paths = array_column($message->attachments ?? [], 'path');
                if ($message->raw_path !== null) {
                    $paths[] = $message->raw_path;
                    $paths[] = $message->raw_path.'.writing';
                }
                if ($paths && ! Storage::disk('local')->delete($paths)) {
                    throw new RuntimeException("Could not remove attachments of message {$message->id}.");
                }
                $deleted[] = $message->id;
                if ($message->email_id !== null && ! $message->is_pending) {
                    $published[] = $message->id;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        if ($published !== []) {
            // The marker and delete share one transaction, using the same expiry cutoff as the inbox.
            (clone $expired)->whereKey($published)->toBase()->update(['inbox_revision' => null]);
        }
        if ($deleted !== []) {
            (clone $expired)->whereKey($deleted)->delete();
        }

        return $failed;
    }
}
