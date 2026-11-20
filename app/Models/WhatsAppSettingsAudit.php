<?php

namespace App\Models;

use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * One entry of the WhatsApp settings audit trail (REQ-WA-44 to REQ-WA-47).
 *
 * Append-only: the application never updates or deletes an entry (REQ-WA-46).
 * Retention pruning stays off until the retention policy is approved (REQ-WA-60).
 * Separate from the general security event log (DEC-AUTH-008).
 */
#[Table('whatsapp_settings_audits')]
#[Fillable(['user_id', 'action', 'environment', 'changes', 'ip_address', 'user_agent', 'created_at'])]
class WhatsAppSettingsAudit extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => WhatsAppAuditAction::class,
            'environment' => WhatsAppEnvironment::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('WhatsApp settings audit entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('WhatsApp settings audit entries cannot be deleted.'));
    }

    /**
     * Record an action. $changes must never contain a secret value.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(
        WhatsAppAuditAction $action,
        ?User $user,
        ?WhatsAppEnvironment $environment = null,
        ?array $changes = null,
        ?Request $request = null,
    ): self {
        return static::create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'environment' => $environment,
            'changes' => $changes,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255, '') : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Entries older than the retention period, only once pruning is enabled (REQ-WA-59, REQ-WA-60).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        if (! config('whatsapp.retention.pruning_enabled')) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays((int) config('whatsapp.retention.settings_audit_days')));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
