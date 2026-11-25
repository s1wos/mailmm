<?php

namespace App\Http\Middleware;

use App\Services\MailboxActionLimit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Symfony\Component\HttpFoundation\Response;

class LimitRepeatedAction
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        if ($request->isMethod('HEAD')) {
            return $next($request);
        }

        $mixed = in_array($action, ['panel', 'page'], true);
        $interactive = match ($action) {
            'panel' => $request->header('X-Mailmm-Action') === 'navigate',
            'page' => $request->header('X-Mailmm-Action') === 'more',
            default => true,
        };
        $resource = match ($action) {
            'letter' => (string) (int) $request->route('message'),
            'page' => $this->pageKey($request),
            'panel' => $request->route('message') !== null
                ? 'letter:'.(int) $request->route('message') : 'inbox:'.max(1, $request->integer('page', 1)),
            default => '',
        };
        if ($request->user() === null && in_array($action, ['letter', 'page'], true)) {
            $resource .= ':'.($request->session()->get('guest_mailbox_key') ?? $request->session()->get('email_id'));
        }

        return MailboxActionLimit::run($request, $action, fn () => $next($request), $resource, $mixed ? 6 : 1, $interactive);
    }

    private function pageKey(Request $request): string
    {
        $encoded = ($request->query() + $request->only('cursor'))['cursor'] ?? null;
        $cursor = Cursor::fromEncoded($encoded)?->toArray();
        if ($request->hasHeader('X-Inbox-Until') && is_numeric($cursor['inbox_revision'] ?? null)) {
            return 'revision:'.(int) $cursor['inbox_revision'];
        }
        if (! is_numeric($cursor['id'] ?? null) || ! is_string($cursor['received_at'] ?? null)) {
            return 'first';
        }
        [$seconds, $fraction] = array_pad(explode('.', $cursor['received_at'], 2), 2, '');

        return (int) $cursor['id'].'/'.$seconds.'.'.rtrim($fraction, '0');
    }
}
