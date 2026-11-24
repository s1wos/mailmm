<?php

namespace App\Models;

use App\Services\MailHtmlPreview;
use App\Services\MailImageUrl;
use App\Services\MailParserService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
        'raw_path',
        'raw_size',
        'raw_sha256',
        'forwarding_context',
    ];

    protected $hidden = ['raw_path', 'raw_size', 'raw_sha256', 'forwarding_context'];

    protected $casts = [
        'received_at' => 'datetime',
        'attachments' => 'array',
        'is_pending' => 'boolean',
        'raw_size' => 'integer',
        'forwarding_context' => 'array',
    ];

    public function hasOriginal(): bool
    {
        return ! $this->is_pending && $this->raw_path !== null && $this->raw_size > 0 && $this->raw_sha256 !== null;
    }

    public static function originalPath(string $deliveryKey): string
    {
        return 'mail/'.substr($deliveryKey, 0, 2).'/'.$deliveryKey.'.eml';
    }

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

    public function htmlPreview(): ?string
    {
        $proxy = MailImageUrl::forMessage($this->id);
        if ($this->hasOriginal() && preg_match('/\A[a-f0-9]{64}\z/', $this->delivery_key ?? '')
            && $this->raw_path === self::originalPath($this->delivery_key)) {
            $stream = null;
            try {
                $stream = Storage::disk('local')->readStream($this->raw_path);
                if (is_resource($stream) && (fstat($stream)['size'] ?? null) === $this->raw_size) {
                    $digest = hash_init('sha256');
                    if (hash_update_stream($digest, $stream) === $this->raw_size
                        && hash_equals($this->raw_sha256, hash_final($digest)) && rewind($stream)) {
                        return app(MailParserService::class)->htmlPreview($stream, $proxy);
                    }
                }
            } catch (\Exception|\ValueError) {
                // Keep the archived body readable if the original cannot be opened or parsed.
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return MailHtmlPreview::render($this->body_html ?? '', proxy: $proxy);
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
