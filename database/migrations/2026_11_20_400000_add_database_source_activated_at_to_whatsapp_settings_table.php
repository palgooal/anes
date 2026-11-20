<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The OTP source state of the WhatsApp settings row (REQ-WA-65, PH-WA-6.1).
 *
 * NULL: the row is being prepared and OTP codes are still sent with the .env
 * settings. A value: the database is the OTP source, since the last activation.
 *
 * Rows that exist when this runs were already the OTP source, so they are marked
 * active and keep their behaviour. Deploy this migration before the code.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->timestamp('database_source_activated_at')->nullable()->after('mode');
        });

        DB::table('whatsapp_settings')
            ->whereNull('database_source_activated_at')
            ->update(['database_source_activated_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    /**
     * Reverse the migrations.
     *
     * Refused while a row is being prepared: without this column the previous code
     * treats any row as the OTP source, so that row, still in "disabled" or "log"
     * mode, would stop every login code. Nothing is changed when refusing.
     *
     * This guard does not protect against deploying older code while the column
     * still exists: that code ignores the column and treats the row as the source.
     */
    public function down(): void
    {
        if (DB::table('whatsapp_settings')->whereNull('database_source_activated_at')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: a WhatsApp settings row is being prepared (database_source_activated_at is NULL). '
                .'Without this column it would become the OTP source and stop login codes. '
                .'Resolve that row first; nothing was changed.'
            );
        }

        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn('database_source_activated_at');
        });
    }
};
