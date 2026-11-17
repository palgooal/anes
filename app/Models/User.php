<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\AccountStatus;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'status', 'phone', 'patient_id', 'qualifications'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => AccountStatus::class,
        ];
    }

    /**
     * The patient a companion follows.
     *
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * Companions registered for this patient.
     *
     * @return HasMany<User, $this>
     */
    public function companions(): HasMany
    {
        return $this->hasMany(User::class, 'patient_id');
    }

    /**
     * @return HasMany<OneTimeCode, $this>
     */
    public function oneTimeCodes(): HasMany
    {
        return $this->hasMany(OneTimeCode::class);
    }

    /**
     * Guides, consultants and administrators (everyone who is not a beneficiary).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function staff(Builder $query): void
    {
        $query->whereNotIn('role', UserRole::beneficiaries());
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /**
     * Phone number in international format for the WhatsApp channel (9665XXXXXXXX).
     */
    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->phone ? '966'.substr($this->phone, 1) : null;
    }

    /**
     * Normalise a Saudi mobile number to the stored local format (05XXXXXXXX).
     * Accepts Arabic-Indic digits, spaces, dashes and +966 / 00966 / 966 prefixes.
     */
    public static function normalizePhone(?string $phone): string
    {
        $digits = strtr((string) $phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D/', '', $digits) ?? '';

        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
