<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

final class PanelArchivePage
{
    private const PER_PAGE = 25;

    public static function for(User $user, string $recipient, int $legacyPage, ?string $cursor): array
    {
        $filter = $user->is_admin ? Str::lower($recipient) : '';
        $listQuery = $filter === '' ? [] : ['recipient' => $recipient];
        $route = $user->is_admin ? 'panel.archive' : 'panel.index';
        $scope = sprintf("panel-archive/1/%d/%d/%s\n", $user->id, $user->is_admin, hash('sha256', $filter));
        $position = self::decode($scope, $cursor);
        $page = $position['page'] ?? ($cursor === null ? max(1, $legacyPage) : 1);
        $query = PanelMessages::for($user);
        if ($filter !== '') {
            $query->where('recipient', $filter);
        }
        ['total' => $total, 'expiry' => $expiry, 'maxId' => $maxId] = $position ?? self::summary($query);
        $query->where('id', '<=', $maxId);
        $window = clone $query;
        $backward = $position['backward'] ?? false;
        // The first page starts at the newest row even if the old cursor now spans more than 25 rows.
        $reverseRows = $backward && $page > 1;
        $direction = $reverseRows ? 'asc' : 'desc';
        $offset = ($page - 1) * self::PER_PAGE;
        if ($position !== null) {
            $window->whereRowValues(['received_at', 'id'], $backward ? '>' : '<', $position['boundary']);
        } elseif ($offset > 0 && $offset < $total) {
            // Preserve bookmarks created before cursor pagination.
            $window->offset($offset);
        }
        $rows = $position === null && $offset >= $total ? collect() : $window->orderBy('received_at', $direction)->orderBy('id', $direction)
            ->limit(self::PER_PAGE + 1)->get(['id', 'recipient', 'from', 'subject', 'received_at']);
        $expected = min(self::PER_PAGE + 1, $backward ? $page * self::PER_PAGE : max(0, $total - $offset));
        // An older pending delivery can become visible inside an existing ID boundary.
        if ($position !== null && (($expiry !== null && now()->gte($expiry)) || $rows->count() !== $expected)) {
            ['total' => $total, 'expiry' => $expiry] = self::summary($query);
        }
        $hasExtra = $rows->count() > self::PER_PAGE;
        $hasNext = $backward ? $page * self::PER_PAGE < $total : $hasExtra;
        $last = max(1, (int) ceil($total / self::PER_PAGE), $rows->isEmpty() ? 1 : $page + (int) $hasNext);
        $rows = $rows->take(self::PER_PAGE);
        if ($reverseRows) {
            $rows = $rows->reverse()->values();
        }

        $link = function (Message $row, int $target, bool $backward) use ($route, $listQuery, $scope, $maxId, $total, $expiry): string {
            // Read the stored timestamp to keep microseconds when comparing the cursor on either database.
            $boundary = [$row->getRawOriginal('received_at'), $row->id];
            $payload = ['page' => $target] + compact('maxId', 'total', 'expiry', 'boundary', 'backward');
            $token = rtrim(strtr(Crypt::encryptString($scope.json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

            return route($route, $listQuery + ($target > 1 ? ['page' => $target] : []) + ['cursor' => $token], false);
        };

        $previous = null;
        if ($page > 1 && ($hasExtra || ! $backward) && $rows->isNotEmpty()) {
            $previous = $link($rows->first(), $page - 1, true);
        } elseif ($rows->isEmpty() && $page > 1) {
            $previous = route($route, $listQuery + ($last > 1 ? ['page' => $last] : []), false);
        }

        return [
            'nextExpiryAt' => $expiry,
            'messages' => [
                'data' => $rows->map(fn (Message $letter) => $letter->panelSummary()),
                'current_page' => $page,
                'last_page' => $last,
                'total' => $total,
                'prev_page_url' => $previous,
                'next_page_url' => $hasNext && $rows->isNotEmpty()
                    ? $link($rows->last(), $page + 1, false) : null,
            ],
            'listQuery' => (object) ($listQuery + ($page > 1 ? ['page' => $page] : [])
                + ($position !== null ? ['cursor' => $cursor] : [])),
        ];
    }

    private static function summary(Builder $query): array
    {
        $summary = (clone $query)->selectRaw('count(*) as total, min(received_at) as received_at, max(id) as max_id')->first();

        return [
            'total' => (int) $summary->total,
            'expiry' => $summary->received_at ? Message::expiresAt($summary->received_at)->toISOString() : null,
            'maxId' => (int) $summary->max_id,
        ];
    }

    private static function decode(string $scope, ?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        try {
            $contents = Crypt::decryptString(strtr($cursor, '-_', '+/'));

            return str_starts_with($contents, $scope)
                ? json_decode(substr($contents, strlen($scope)), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|JsonException) {
            return null;
        }
    }
}
