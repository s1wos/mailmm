<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class MailForward extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['rule_version' => 'integer', 'attempts' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->where('initiator_id', $user->id);
            if (! $user->is_admin) {
                $query->orWhere('mode', 'auto');
            }
        });
    }

    public function panelData(): array
    {
        return $this->only(['id', 'mode', 'status', 'destination', 'request_id', 'error_code', 'created_at']);
    }

    public function logOutcome(): void
    {
        $level = in_array($this->status, ['failed', 'unknown', 'bounced'], true) ? 'warning' : 'info';
        $context = ['id' => $this->id, 'status' => $this->status, 'attempts' => (int) $this->attempts,
            'reason' => $this->error_code, 'age_seconds' => (int) $this->created_at->diffInSeconds(now())];
        $this->getConnection()->afterCommit(static fn () => Log::log($level, 'Mail forwarding attempt completed', $context));
    }
}
