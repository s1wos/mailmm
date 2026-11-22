<?php

namespace App\Services;

use App\Models\Email;
use App\Models\Message;
use Carbon\CarbonImmutable;

class MailDashboard
{
    public function overview(): array
    {
        $now = CarbonImmutable::now('UTC');
        $start = $now->startOfDay()->subDays(6);
        $lastDay = $now->subDay()->format('Y-m-d H:i:s.u');
        $daily = Message::retained()
            ->whereBetween('received_at', [$start->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u')])
            ->selectRaw('DATE(received_at) AS day, COUNT(email_id) AS mailbox, COUNT(*) - COUNT(email_id) AS archive')
            ->selectRaw('COUNT(CASE WHEN received_at >= ? THEN 1 END) AS received_recent', [$lastDay])
            ->selectRaw('COUNT(CASE WHEN received_at >= ? AND email_id IS NULL THEN 1 END) AS archive_recent', [$lastDay])
            ->groupByRaw('DATE(received_at)')->toBase()->get()->keyBy('day');
        $boxes = Email::active()
            ->selectRaw('COUNT(CASE WHEN owner_user_id IS NULL AND expires_at IS NOT NULL THEN 1 END) AS guests')
            ->selectRaw('COUNT(CASE WHEN owner_user_id IS NOT NULL AND expires_at IS NULL THEN 1 END) AS permanent')
            ->toBase()->first();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->addDays($i)->toDateString();
            $row = $daily->get($date);
            $days[] = ['date' => $date, 'mailbox' => (int) ($row->mailbox ?? 0), 'archive' => (int) ($row->archive ?? 0)];
        }

        return [
            'serverTime' => $now->toISOString(),
            'metrics' => [
                'received24h' => (int) $daily->sum('received_recent'),
                'archived24h' => (int) $daily->sum('archive_recent'),
                'guests' => (int) $boxes->guests,
                'permanent' => (int) $boxes->permanent,
                'activeUsers' => SitePresence::activeUsers(),
            ],
            'days' => $days,
            'recentMessages' => Message::retained()->latest('received_at')->latest('id')->limit(6)
                ->get(['id', 'from', 'recipient', 'subject', 'received_at'])->map->panelSummary(),
        ];
    }
}
