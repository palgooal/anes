<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phones were stored as Saudi local numbers (05XXXXXXXX). Store them as
     * international digits (9665XXXXXXXX) so other countries fit too.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 15)->nullable()->change();
        });

        $this->rewritePhones(fn (string $phone): ?string => preg_match('/^05\d{8}$/', $phone) ? '966'.substr($phone, 1) : $phone);
    }

    /**
     * Reverse the migrations.
     *
     * Saudi numbers go back to 05XXXXXXXX. Numbers from other countries do not
     * fit the old format and are cleared.
     */
    public function down(): void
    {
        $this->rewritePhones(fn (string $phone): ?string => match (true) {
            (bool) preg_match('/^9665\d{8}$/', $phone) => '0'.substr($phone, 3),
            strlen($phone) > 10 => null,
            default => $phone,
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 10)->nullable()->change();
        });
    }

    /**
     * Rewrite every stored phone in PHP, so the migration works the same on MySQL, SQLite and PostgreSQL.
     *
     * @param  callable(string): ?string  $rewrite
     */
    private function rewritePhones(callable $rewrite): void
    {
        DB::table('users')->whereNotNull('phone')->orderBy('id')->chunkById(200, function ($users) use ($rewrite): void {
            foreach ($users as $user) {
                $phone = $rewrite($user->phone);

                if ($phone !== $user->phone) {
                    DB::table('users')->where('id', $user->id)->update(['phone' => $phone]);
                }
            }
        });
    }
};
