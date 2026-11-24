<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Services\ForwardingRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $request->user();

        return [
            'id' => $this->id,
            'from' => $this->from,
            'subject' => $this->subject,
            'body_text' => $this->resource->readableBody(),
            'html_preview' => $this->resource->htmlPreview(),
            'received_at' => $this->received_at->toISOString(),
            'expires_at' => Message::expiresAt($this->received_at)->toISOString(),
            'forwarding' => $this->when($owner && ! $owner->is_admin, fn () => ForwardingRequests::availability($this->resource)),
            'attachments' => collect($this->attachments)->map(fn (array $file, int $index) => [
                'filename' => $file['filename'],
                'url' => route('mailbox.attachment', [$this->id, $index], false),
                'mime' => $file['mime'],
                'size' => $file['size'],
            ])->values(),
        ];
    }
}
