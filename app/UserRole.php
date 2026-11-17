<?php

namespace App;

enum UserRole: string
{
    case Patient = 'patient';
    case Companion = 'companion';
    case Guide = 'guide';
    case Fiqh = 'fiqh';
    case Medical = 'medical';
    case Legal = 'legal';
    case Admin = 'admin';

    /**
     * Human readable (Arabic) name of the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Patient => 'المريض',
            self::Companion => 'المرافق',
            self::Guide => 'المرشد الديني والروحي',
            self::Fiqh => 'المستشار الفقهي',
            self::Medical => 'المستشار الطبي',
            self::Legal => 'المستشار العدلي',
            self::Admin => 'مدير النظام',
        };
    }

    /**
     * Beneficiaries sign in with their phone number and a WhatsApp code.
     */
    public function isBeneficiary(): bool
    {
        return in_array($this, self::beneficiaries(), true);
    }

    /**
     * Staff sign in with email, password and a two-factor code.
     */
    public function isStaff(): bool
    {
        return ! $this->isBeneficiary();
    }

    /**
     * The panel key the dashboard script understands.
     */
    public function panel(): string
    {
        return match ($this) {
            self::Patient, self::Companion => 'patient',
            self::Medical => 'med',
            default => $this->value,
        };
    }

    /**
     * @return list<self>
     */
    public static function beneficiaries(): array
    {
        return [self::Patient, self::Companion];
    }

    /**
     * Roles a person may apply for through the "join the team" form.
     *
     * @return list<self>
     */
    public static function joinable(): array
    {
        return [self::Guide, self::Fiqh, self::Medical, self::Legal];
    }
}
