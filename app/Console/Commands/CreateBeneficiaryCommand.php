<?php

namespace App\Console\Commands;

use App\AccountStatus;
use App\Models\User;
use App\PhoneNumber;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('beneficiary:create {name : Full name} {phone : Mobile number, e.g. 0599123456 or +970599123456} {--country= : Calling code for a national number (966, 970 or 972)} {--companion-of= : Phone of the patient this person accompanies}')]
#[Description('Register a patient (or a companion) who can then sign in with a WhatsApp code')]
class CreateBeneficiaryCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $phone = PhoneNumber::normalize($this->argument('phone'), $this->option('country'));

        if (! $phone) {
            $this->error('رقم غير صحيح، أو دولته غير مسموحة في AUTH_PHONE_COUNTRIES ('.implode(',', PhoneNumber::allowedCountries()).').');

            return self::FAILURE;
        }

        if (User::where('phone', $phone)->exists()) {
            $this->error('هذا الرقم مسجّل لحساب آخر: '.PhoneNumber::format($phone));

            return self::FAILURE;
        }

        $patient = null;

        if ($this->option('companion-of')) {
            $patient = User::where('role', UserRole::Patient)
                ->where('phone', PhoneNumber::normalize($this->option('companion-of'), $this->option('country')))
                ->first();

            if (! $patient) {
                $this->error('لم يُعثر على مريض بهذا الرقم: '.$this->option('companion-of'));

                return self::FAILURE;
            }
        }

        $user = User::create([
            'name' => $this->argument('name'),
            'phone' => $phone,
            'role' => $patient ? UserRole::Companion : UserRole::Patient,
            'status' => AccountStatus::Active,
            'patient_id' => $patient?->id,
        ]);

        $this->info(sprintf(
            'سُجّل %s (%s) بالرقم %s%s. يمكنه الدخول من /login.',
            $user->name,
            $user->role->label(),
            PhoneNumber::format($phone),
            $patient ? ' مرافقاً لـ '.$patient->name : '',
        ));

        return self::SUCCESS;
    }
}
