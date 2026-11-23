<?php

namespace App\Models;

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
