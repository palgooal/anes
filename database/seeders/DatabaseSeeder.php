<?php

namespace Database\Seeders;

use App\AccountStatus;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo accounts that match the names used in the dashboard demo data.
     * Staff password: demo1234. Phones are international digits (9665…).
     * Login codes are written to storage/logs while WHATSAPP_DRIVER=log.
     */
    public function run(): void
    {
        $patient = User::updateOrCreate(['phone' => '966551234567'], [
            'name' => 'عبدالله محمد السبيعي',
            'role' => UserRole::Patient,
            'status' => AccountStatus::Active,
        ]);

        User::updateOrCreate(['phone' => '966511111111'], [
            'name' => 'منى عبدالله',
            'role' => UserRole::Companion,
            'status' => AccountStatus::Active,
            'patient_id' => $patient->id,
        ]);

        $staff = [
            ['guide@demo.sa', 'أ. عبدالرحمن البقمي', UserRole::Guide, AccountStatus::Active, '966500000101'],
            ['fiqh@demo.sa', 'د. صالح الشهري', UserRole::Fiqh, AccountStatus::Active, '966500000102'],
            ['med@demo.sa', 'د. سليمان العمري', UserRole::Medical, AccountStatus::Active, '966500000103'],
            ['legal@demo.sa', 'أ. ماجد الحارثي', UserRole::Legal, AccountStatus::Active, '966500000104'],
            ['admin@demo.sa', 'مدير النظام', UserRole::Admin, AccountStatus::Active, '966500000105'],
            ['pending@demo.sa', 'مرشد قيد المراجعة', UserRole::Guide, AccountStatus::Pending, '966500000106'],
            ['suspended@demo.sa', 'حساب موقوف', UserRole::Guide, AccountStatus::Suspended, '966500000107'],
        ];

        foreach ($staff as [$email, $name, $role, $status, $phone]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'status' => $status,
                'phone' => $phone,
                'password' => 'demo1234',
                'email_verified_at' => now(),
            ]);
        }
    }
}
