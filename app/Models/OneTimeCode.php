<?php

namespace App\Models;

use App\CodePurpose;
use App\WhatsAppSendStatus;
use Database\Factories\OneTimeCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * A short numeric code sent on WhatsApp. Only its hash is stored.
 */
#[Fillable(['user_id', 'purpose', 'code', 'expires_at', 'consumed_at', 'invalidated_at', 'invalidation_reason'])]
#[Hidden(['code'])]
class OneTimeCode extends Model
{
    /** @use HasFactory<OneTimeCodeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => CodePurpose::class,
            'code' => 'hashed',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Replace any earlier code for this purpose with a fresh one.
     *
     * @return array{0: self, 1: string} The stored code and its plain value.
     */
    public static function issue(User $user, CodePurpose $purpose): array
    {
        $length = (int) config('auth.one_time_codes.length');
        $plain = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        $user->oneTimeCodes()->where('purpose', $purpose)->delete();

        $oneTimeCode = $user->oneTimeCodes()->create([
            'purpose' => $purpose,
            'code' => $plain,
            'expires_at' => now()->addMinutes((int) config('auth.one_time_codes.expires_minutes')),
        ]);

        return [$oneTimeCode, $plain];
    }

    /**
     * Replace any earlier code for this purpose with a fresh one and return the plain code.
     */
    public static function issueFor(User $user, CodePurpose $purpose): string
    {
        return self::issue($user, $purpose)[1];
    }

    /**
     * The newest code for this purpose that can still be used: not used yet and not invalidated.
     */
    public static function latestFor(User $user, CodePurpose $purpose): ?self
    {
        return $user->oneTimeCodes()
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();
    }

    /**
     * The newest code issued for this purpose, including one whose sending failed.
     * The resend interval is counted from it (REQ-WA-31).
     */
    public static function latestIssuedFor(User $user, CodePurpose $purpose): ?self
    {
        return $user->oneTimeCodes()
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function matches(string $plain): bool
    {
        return ! $this->isInvalidated() && ! $this->isExpired() && Hash::check($plain, $this->code);
    }

    /**
     * Make the code unusable because its delivery failed or is uncertain (REQ-WA-27).
     */
    public function invalidate(WhatsAppSendStatus $reason): void
    {
        $this->forceFill(['invalidated_at' => now(), 'invalidation_reason' => $reason->value])->save();
    }

    /**
     * Seconds left before the user may ask for another code.
     */
    public function secondsUntilResend(): int
    {
        $availableAt = $this->created_at->addSeconds((int) config('auth.one_time_codes.resend_after_seconds'));

        return max(0, (int) ceil(now()->diffInSeconds($availableAt, false)));
    }

    /**
     * Use the code, in one conditional UPDATE (PH-WA-8.5): only while it is still not
     * used, not invalidated and not expired in the database itself. A code invalidated
     * meanwhile, by a failed delivery from the webhook (REQ-WA-32), or used by a
     * parallel request, is refused (REQ-WA-29). Returns whether this request used it.
     */
    public function consumeIfUsable(): bool
    {
        $now = now();

        $used = static::query()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now, 'updated_at' => $now]) === 1;

        if ($used) {
            $this->forceFill(['consumed_at' => $now, 'updated_at' => $now])->syncOriginal();
        }

        return $used;
    }
}
