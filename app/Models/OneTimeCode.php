<?php

namespace App\Models;

use App\CodePurpose;
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
#[Fillable(['user_id', 'purpose', 'code', 'expires_at', 'consumed_at'])]
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
     * Replace any earlier code for this purpose with a fresh one and return the plain code.
     */
    public static function issueFor(User $user, CodePurpose $purpose): string
    {
        $length = (int) config('auth.one_time_codes.length');
        $plain = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        $user->oneTimeCodes()->where('purpose', $purpose)->delete();

        $user->oneTimeCodes()->create([
            'purpose' => $purpose,
            'code' => $plain,
            'expires_at' => now()->addMinutes((int) config('auth.one_time_codes.expires_minutes')),
        ]);

        return $plain;
    }

    /**
     * The newest code for this purpose that has not been used yet.
     */
    public static function latestFor(User $user, CodePurpose $purpose): ?self
    {
        return $user->oneTimeCodes()
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function matches(string $plain): bool
    {
        return ! $this->isExpired() && Hash::check($plain, $this->code);
    }

    /**
     * Seconds left before the user may ask for another code.
     */
    public function secondsUntilResend(): int
    {
        $availableAt = $this->created_at->addSeconds((int) config('auth.one_time_codes.resend_after_seconds'));

        return max(0, (int) ceil(now()->diffInSeconds($availableAt, false)));
    }

    public function markAsConsumed(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }
}
