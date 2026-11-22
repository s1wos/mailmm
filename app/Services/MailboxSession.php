<?php

namespace App\Services;

use App\Models\Email;
use App\Models\MailboxSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class MailboxSession
{
    public const ISSUE_LIMIT_PREFIX = 'guest-mailbox-issue:';

    public function __construct(
        private readonly EmailGeneratorService $generator,
        private readonly MailboxDomainCatalog $domains,
    ) {}

    public function current(Request $request): ?Email
    {
        if ($this->isOwner($request)) {
            return $request->user()->mailbox()->active()->first();
        }

        $email = $this->guestMailboxes($request)->active()->first();
        $this->rememberGuest($request, $email);

        return $email;
    }

    public function getOrCreate(Request $request): Email
    {
        return $this->current($request) ?? $this->issue($request);
    }

    public function rotate(Request $request): Email
    {
        return $this->issue($request, $request->only('domain', 'format'));
    }

    private function issue(Request $request, ?array $selection = null): Email
    {
        abort_if($this->isOwner($request), 403);

        $email = DB::transaction(function () use ($request, $selection) {
            $previous = $this->guestMailboxes($request)->lockForUpdate()->first();
            if ($selection !== null) {
                $this->confirmAddress($request, $previous);
            } elseif ($previous?->expires_at->isFuture()) {
                return $previous;
            }
            if (! MailboxSettings::current(lockForIssuance: true)->guest_issuance_enabled) {
                throw new HttpResponseException(response()->json([
                    'code' => 'guest_issuance_paused',
                    'message' => 'Выдача временных адресов приостановлена.',
                ], 503));
            }
            $data = Validator::make($selection ?? [], [
                'domain' => ['sometimes', 'required', 'string', 'max:252'],
                'format' => ['sometimes', 'required', 'string', 'in:random,words'],
            ])->validate();
            $format = $data['format'] ?? 'random';
            $domain = $this->domains->forIssuance($data['domain'] ?? null, $format);
            $key = self::ISSUE_LIMIT_PREFIX.hash('sha256', (string) $request->ip());
            $limited = ! $request->user()?->is_admin;
            if ($limited && RateLimiter::tooManyAttempts($key, 10)) {
                $this->rejectQuota($key);
            }
            $generate = fn () => $this->generator->generate($domain->name, $format);
            $newEmail = $selection === null ? $generate() : MailboxActionLimit::run($request, 'rotate', $generate);
            if ($limited && RateLimiter::increment($key, 3600) > 10) {
                $this->rejectQuota($key);
            }
            $this->closeAddress($previous);
            $newEmail->guest_session_key = $this->guestKey($request);
            $newEmail->save();

            return $newEmail;
        });
        $this->rememberGuest($request, $email);

        return $email;
    }

    private function rejectQuota(string $key): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'guest_issuance_quota_exceeded',
            'message' => 'Лимит выдачи адресов исчерпан.',
        ], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]));
    }

    public function close(Request $request): void
    {
        abort_if($this->isOwner($request), 403);
        DB::transaction(function () use ($request) {
            $email = $this->guestMailboxes($request)->lockForUpdate()->first();
            $this->confirmAddress($request, $email);
            $this->closeAddress($email);
        });
        $request->session()->forget(['guest_mailbox_key', 'email_id']);
    }

    private function confirmAddress(Request $request, ?Email $email): void
    {
        $id = $email?->id;
        $expected = $request->header('X-Expected-Mailbox-Id');
        if ($expected !== ($id === null ? null : (string) $id)) {
            throw new HttpResponseException(response()->json([
                'code' => 'mailbox_changed',
                'message' => 'Адрес изменился. Обновите ящик и подтвердите действие ещё раз.',
            ], 409));
        }
    }

    private function closeAddress(?Email $email): void
    {
        if ($email !== null) {
            $email->closed_at = now();
            $email->guest_session_key = null;
            $email->save();
        }
    }

    private function guestMailboxes(Request $request): Builder
    {
        $legacyId = $request->session()->get('email_id');

        return Email::whereNull('owner_user_id')->whereNotNull('expires_at')->whereNull('closed_at')
            ->where(function (Builder $query) use ($request, $legacyId) {
                $query->where('guest_session_key', $this->guestKey($request));
                if ($legacyId !== null && ! $request->session()->has('guest_mailbox_key')) {
                    $query->orWhere('id', $legacyId);
                } elseif (! $request->session()->hasAny(['guest_mailbox_key', 'email_id'])
                    && ($mailbox = GuestMailboxCookie::mailbox($request)) !== null) {
                    $query->orWhere(fn (Builder $recovered) => $recovered->where($mailbox));
                }
            });
    }

    private function guestKey(Request $request): string
    {
        $session = $request->session();

        return $session->get('guest_mailbox_key') ?? hash('sha256', $session->has('email_id')
            ? 'email:'.$session->get('email_id') : 'token:'.$session->token());
    }

    private function rememberGuest(Request $request, ?Email $email): void
    {
        if ($email?->guest_session_key !== null) {
            $request->attributes->set(GuestMailboxCookie::class, $email);
            $request->session()->put('guest_mailbox_key', $email->guest_session_key);
            $request->session()->forget('email_id');
        }
    }

    private function isOwner(Request $request): bool
    {
        return $request->user() !== null && ! $request->user()->is_admin;
    }
}
