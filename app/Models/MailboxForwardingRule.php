<?php

namespace App\Models;

use App\Services\ForwardingRequests;
use Illuminate\Database\Eloquent\Model;

class MailboxForwardingRule extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'code_attempts' => 'integer',
            'activated_at' => 'immutable_datetime',
            'code_expires_at' => 'immutable_datetime',
            'resend_at' => 'immutable_datetime',
        ];
    }

    public function panelData(): array
    {
        return $this->only(['status', 'version', 'destination', 'activated_at', 'code_expires_at', 'resend_at', 'disabled_reason', 'last_error_code'])
            + ['sending_enabled' => ForwardingRequests::automaticSince() !== null];
    }
}
