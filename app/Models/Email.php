<?php

namespace App\Models;

use App\Services\ForwardingRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    use HasFactory;

    protected $fillable = ['email', 'owner_user_id', 'expires_at', 'closed_at'];

    protected $hidden = ['guest_session_key'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    protected function performUpdate(Builder $query): bool
    {
        if (! $this->isDirty(['closed_at', 'expires_at', 'owner_user_id'])) {
            return parent::performUpdate($query);
        }
        $original = $this->getRawOriginal();
        $ownerId = array_key_exists('owner_user_id', $original)
            ? $original['owner_user_id'] : self::whereKey($this->id)->value('owner_user_id');
        if ($ownerId === null) {
            return parent::performUpdate($query);
        }

        // Serialize the owner's final manual request, the mailbox change and revocation.
        return $this->getConnection()->transaction(function () use ($query, $ownerId) {
            User::whereKey($ownerId)->lockForUpdate()->first(['id']);
            $previous = self::activePermanent()->where('owner_user_id', $ownerId)->lockForUpdate()->find($this->id);
            $saved = parent::performUpdate($query);
            if ($saved && $previous && ! self::activePermanent()->where('owner_user_id', $ownerId)->whereKey($this->id)->exists()) {
                ForwardingRequests::revokeMailbox($previous, $ownerId, 'mailbox_closed');
            }

            return $saved;
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->where(function (Builder $query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', $this->fromDateTime(now()));
        });
    }

    public function scopeActivePermanent(Builder $query): Builder
    {
        return $query->whereNotNull('owner_user_id')->whereNull('expires_at')->whereNull('closed_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
