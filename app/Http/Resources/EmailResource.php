<?php

namespace App\Http\Resources;

use App\Models\Email;
use App\Services\MailboxInbox;
use App\Services\MailboxSocketGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Email */
class EmailResource extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('X-Inbox-Snapshot', app(MailboxInbox::class)->snapshot($this->resource));
        $response->header('X-Server-Time', now()->toISOString());
        $response->header('X-Mailbox-Grant-Expires-At', MailboxSocketGrant::expiresAt($this->resource)->toISOString());
    }

    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'expires_at' => $this->expires_at?->toISOString()];
    }
}
