<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            'subject' => $this->subject,
            'body_text' => $this->resource->readableBody(),
            'received_at' => $this->received_at->toISOString(),
            'expires_at' => Message::expiresAt($this->received_at)->toISOString(),
            'attachments' => collect($this->attachments)->map(fn (array $file, int $index) => [
                'filename' => $file['filename'],
                'url' => route('mailbox.attachment', [$this->id, $index], false),
                'mime' => $file['mime'],
                'size' => $file['size'],
            ])->values(),
        ];
    }
}
