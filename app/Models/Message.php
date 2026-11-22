<?php

namespace App\Models;

use App\Services\MailParserService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    public const RETENTION_DAYS = 365;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'email_id',
        'recipient',
        'delivery_key',
        'is_pending',
        'from',
        'subject',
        'body_text',
        'body_html',
        'received_at',
        'attachments',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'attachments' => 'array',
        'is_pending' => 'boolean',
    ];

    public function scopeRetained(Builder $query): Builder
    {
        return $query->where('is_pending', false)->where('received_at', '>', now()->subDays(self::RETENTION_DAYS)->format($this->dateFormat));
    }

    public static function expiresAt(CarbonInterface $receivedAt): CarbonInterface
    {
        return $receivedAt->copy()->addDays(self::RETENTION_DAYS);
    }

    public function panelSummary(): array
    {
        return $this->only(['id', 'recipient', 'from', 'subject', 'received_at']) + [
            'expires_at' => self::expiresAt($this->received_at)->toISOString(),
        ];
    }

    public function readableBody(): ?string
    {
        if ($this->delivery_key === null && trim($this->body_text ?? '') === '' && $this->body_html !== null && $this->body_html !== '') {
            return MailParserService::readableHtml($this->body_html);
        }

        return $this->body_text;
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
