<?php

namespace App\Services;

use App\DTO\PostfixReceipt;
use App\Events\NewMessageReceived;
use App\Exceptions\ForwardingCheckFailed;
use App\Models\Email;
use App\Models\MailboxForwardingRule;
use App\Models\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MailDeliveryService
{
    public function __construct(private MailParserService $parser) {}

    public function deliver(string $recipient, string $raw, ?PostfixReceipt $receipt = null, ?array $smtpContext = null): void
    {
        $receivedAt = $receipt?->receivedAt ?? now()->toImmutable();
        if ($receivedAt->lessThanOrEqualTo(now()->subDays(Message::RETENTION_DAYS))) {
            return;
        }
        $key = hash('sha256', $recipient."\0".$raw);
        if (Message::where('delivery_key', $key)->where('is_pending', false)->exists()) {
            return;
        }

        if ($receipt !== null && preg_match('/\A[^\r\n]*\n/', $raw)) {
            $raw = str_replace("\n", "\r\n", $raw);
        }
        $parsed = $this->parser->parse($raw);
        $files = [];
        foreach ($parsed->attachments as $i => $attachment) {
            $files[] = [
                ...Arr::only($attachment, ['filename', 'mime']),
                'path' => 'mail/'.substr($key, 0, 2).'/'.$key.'-'.$i,
                'size' => strlen($attachment['content']),
            ];
        }

        $original = [
            'raw_path' => 'mail/'.substr($key, 0, 2).'/'.$key.'.eml',
            'raw_size' => strlen($raw),
            'raw_sha256' => hash('sha256', $raw),
        ];
        $message = DB::transaction(function () use ($recipient, $key, $receivedAt, $receipt, $parsed, $files, $original, $smtpContext) {
            $latest = ($receipt?->latestPossible ?? $receivedAt)->format('Y-m-d H:i:s.u');
            $email = Email::query()->where('email', $recipient)
                ->where('created_at', $receipt ? '<' : '<=', $receivedAt->format('Y-m-d H:i:s.u'))
                ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $latest))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $latest))
                ->lockForUpdate()->first();

            $content = [
                ...$original,
                'from' => $parsed->from,
                'subject' => $parsed->subject,
                'body_text' => $parsed->bodyText,
                'attachments' => $files,
            ];
            $message = Message::query()->firstOrCreate(['delivery_key' => $key], [
                ...$content,
                'email_id' => $email?->id,
                'recipient' => $recipient,
                'received_at' => $receivedAt,
                'is_pending' => true,
                'forwarding_context' => $receipt && (config('forwarding.manual_enabled') || ($email?->owner_user_id && config('forwarding.auto_enabled')))
                    ? $smtpContext : null,
            ]);
            if (! $message->wasRecentlyCreated && $message->is_pending) {
                $pending = Message::whereKey($message->id)->where('is_pending', true)->lockForUpdate()->first();
                if ($pending) {
                    $obsolete = array_diff(array_column($pending->attachments ?? [], 'path'), array_column($files, 'path'));
                    if ($obsolete && ! Storage::disk('local')->delete($obsolete)) {
                        throw new RuntimeException('Could not remove an obsolete pending attachment.');
                    }
                    $pending->update($content);
                }
            }

            return $message;
        });

        $context = $message->forwarding_context;
        $manualCandidate = config('forwarding.manual_enabled') && isset($context['sender']) && $context['sender'] !== '';
        $autoCandidate = ForwardingRequests::automaticSince() !== null && $message->email_id !== null
            && ($context['sender'] ?? null) !== '' && ! ForwardingIngress::suppressAutomatic($raw);
        $skipReason = null;
        // Capture authentication while the sender's DNS and the actual SMTP session are still current.
        if ($manualCandidate || ($autoCandidate && MailboxForwardingRule::where('email_id', $message->email_id)->where('status', 'active')->exists())) {
            try {
                $context['authentication_results'] = app(ForwardingIngress::class)->authenticationResults($message, $raw);
            } catch (ForwardingCheckFailed $failure) {
                $skipReason = $failure->temporary ? null : $failure->reason;
                if ($manualCandidate) {
                    if ($failure->temporary) {
                        $context['manual_check_deferred'] = true;
                    } else {
                        $context['blocked_reason'] = $failure->reason;
                    }
                }
            }
        }
        DB::transaction(function () use ($message, $parsed, $raw, $context, $manualCandidate, $autoCandidate, $skipReason) {
            $message = Message::query()->lockForUpdate()->findOrFail($message->id);
            if (! $message->is_pending) {
                return;
            }
            $disk = Storage::disk('local');
            // The pending row owns both names, including after a crash before rename.
            $temporary = $message->raw_path.'.writing';
            if (! $disk->put($temporary, $raw)
                || $disk->size($temporary) !== $message->raw_size
                || $disk->checksum($temporary, ['checksum_algo' => 'sha256']) !== $message->raw_sha256
                || ! $disk->move($temporary, $message->raw_path)) {
                throw new RuntimeException('Could not persist an inbound original.');
            }
            foreach ($message->attachments as $i => $file) {
                if (! $disk->put($file['path'], $parsed->attachments[$i]['content'])
                    || $disk->size($file['path']) !== $file['size']) {
                    throw new RuntimeException('Could not persist an inbound attachment.');
                }
            }
            $message->update(['is_pending' => false, 'forwarding_context' => $manualCandidate || $autoCandidate ? $context : null]);

            if ($autoCandidate && ! ForwardingRequests::automatic($message, $skipReason) && ! $manualCandidate && $context !== null) {
                $message->update(['forwarding_context' => null]);
            }

            if ($message->email_id !== null && Email::query()->active()->whereKey($message->email_id)->exists()) {
                MailboxNotifications::publish(new NewMessageReceived($message->email_id, $message->id));
            }
        });
    }
}
