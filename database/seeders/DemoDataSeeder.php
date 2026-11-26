<?php

namespace Database\Seeders;

use App\AccountStatus;
use App\Models\User;
use App\UserRole;
use Database\Seeders\Support\DemoSeedGuard;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo accounts for browsing the dashboards locally (ANIS-SEC-01, F3).
 *
 * Runs only on the dedicated demo database, and only when every condition of
 * DemoSeedGuard holds; it is never called by DatabaseSeeder. To use it:
 *
 *   1. Create the empty file database/anis-demo.sqlite (ignored by Git).
 *   2. In .env: APP_ENV=local, DB_CONNECTION=sqlite,
 *      DB_DATABASE=<absolute path to database/anis-demo.sqlite>,
 *      WHATSAPP_DRIVER=log, MAIL_MAILER=log, then php artisan config:clear.
 *   3. php artisan migrate
 *   4. ANIS_DEMO_SEED=1 php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
 *
 * Staff password: demo1234. Emails use the reserved domain anis-demo.test, which
 * cannot receive mail. Login codes are written to storage/logs (driver "log").
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public const PASSWORD = 'demo1234';

    public const EMAIL_DOMAIN = 'anis-demo.test';

    /**
     * Staff accounts: email, name, role, status, phone.
     *
     * @var list<array{0: string, 1: string, 2: UserRole, 3: AccountStatus, 4: string}>
     */
    private const STAFF = [
        ['guide@anis-demo.test', 'أ. عبدالرحمن البقمي', UserRole::Guide, AccountStatus::Active, '966500000102'],
        ['fiqh@anis-demo.test', 'د. صالح الشهري', UserRole::Fiqh, AccountStatus::Active, '966500000103'],
        ['med@anis-demo.test', 'د. سليمان العمري', UserRole::Medical, AccountStatus::Active, '966500000104'],
        ['legal@anis-demo.test', 'أ. ماجد الحارثي', UserRole::Legal, AccountStatus::Active, '966500000105'],
        ['admin@anis-demo.test', 'مدير النظام', UserRole::Admin, AccountStatus::Active, '966500000106'],
        ['pending@anis-demo.test', 'مرشد قيد المراجعة', UserRole::Guide, AccountStatus::Pending, '966500000107'],
        ['suspended@anis-demo.test', 'حساب موقوف', UserRole::Guide, AccountStatus::Suspended, '966500000108'],
    ];

    private const PATIENT_PHONE = '966500000100';

    private const COMPANION_PHONE = '966500000101';

    public function run(): void
    {
        DemoSeedGuard::assertSafe($this->container, $this->databaseFile(), self::emails(), self::phones());

        DB::transaction(function () {
            $patient = User::updateOrCreate(['phone' => self::PATIENT_PHONE], [
                'name' => 'عبدالله محمد السبيعي',
                'role' => UserRole::Patient,
                'status' => AccountStatus::Active,
            ]);

            User::updateOrCreate(['phone' => self::COMPANION_PHONE], [
                'name' => 'منى عبدالله',
                'role' => UserRole::Companion,
                'status' => AccountStatus::Active,
                'patient_id' => $patient->id,
            ]);

            foreach (self::STAFF as [$email, $name, $role, $status, $phone]) {
                User::updateOrCreate(['email' => $email], [
                    'name' => $name,
                    'role' => $role,
                    'status' => $status,
                    'phone' => $phone,
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                ]);
            }
        });
    }

    /**
     * The only database this seeder may write to.
     */
    protected function databaseFile(): string
    {
        return database_path('anis-demo.sqlite');
    }

    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        return array_map(fn (array $staff) => $staff[0], self::STAFF);
    }

    /**
     * @return list<string>
     */
    public static function phones(): array
    {
        return [self::PATIENT_PHONE, self::COMPANION_PHONE, ...array_map(fn (array $staff) => $staff[4], self::STAFF)];
    }
}
