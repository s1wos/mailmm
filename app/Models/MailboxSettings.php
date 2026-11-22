<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxSettings extends Model
{
    public $timestamps = false;

    protected $table = 'mailbox_settings';

    protected $casts = ['guest_issuance_enabled' => 'boolean'];

    public static function current(bool $lockForIssuance = false): self
    {
        $settings = static::query();
        if ($lockForIssuance) {
            $settings->sharedLock();
        }

        return $settings->findOrFail(1);
    }
}
