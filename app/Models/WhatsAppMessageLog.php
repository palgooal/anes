<?php

namespace App\Models;

use App\WhatsAppDeliveryStatus;
use App\WhatsAppEnvironment;
use App\WhatsAppMessagePurpose;
use App\WhatsAppSendStatus;
use Database\Factories\WhatsAppMessageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp send attempt (docs/ANIS-WHATSAPP-SETTINGS-001.md, REQ-WA-48 to REQ-WA-51).
 *
 * send_status is Meta's answer to the request; delivery_status is the actual
 * delivery reported later by the webhook. Holds no code, token, request or
 * response body, and only a hash and the last 4 digits of the recipient.
 */
#[Table('whatsapp_message_logs')]
#[Fillable([
    'environment', 'purpose', 'one_time_code_id', 'user_id',
    'recipient_hash', 'recipient_last4', 'template_name', 'template_language',
    'send_status', 'wamid', 'error_code', 'error_title', 'http_status', 'duration_ms', 'attempted_at',
    'delivery_status', 'delivery_error_code', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
])]
class WhatsAppMessageLog extends Model
{
    /** @use HasFactory<WhatsAppMessageLogFactory> */
    use HasFactory, Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => WhatsAppEnvironment::class,
            'purpose' => WhatsAppMessagePurpose::class,
            'send_status' => WhatsAppSendStatus::class,
            'delivery_status' => WhatsAppDeliveryStatus::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The searchable fingerprint of a recipient number: HMAC-SHA256 keyed with the app key.
     */
    public static function recipientHash(string $number): string
    {
        return hash_hmac('sha256', $number, (string) config('app.key'));
    }

    /**
     * Rows older than the retention period, but only once pruning is enabled
     * (REQ-WA-59, REQ-WA-60). While disabled, nothing is ever selected for deletion.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        if (! config('whatsapp.retention.pruning_enabled')) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays((int) config('whatsapp.retention.message_logs_days')));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<OneTimeCode, $this>
     */
    public function oneTimeCode(): BelongsTo
    {
        return $this->belongsTo(OneTimeCode::class);
    }
}
