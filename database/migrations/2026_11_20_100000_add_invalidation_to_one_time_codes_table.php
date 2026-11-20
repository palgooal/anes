<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A code whose delivery failed or is uncertain is invalidated and can never
     * be used (docs/ANIS-WHATSAPP-SETTINGS-001.md, REQ-WA-27 to REQ-WA-29).
     * The row is kept so the resend interval still counts the failed attempt.
     */
    public function up(): void
    {
        Schema::table('one_time_codes', function (Blueprint $table) {
            $table->timestamp('invalidated_at')->nullable()->after('consumed_at');
            $table->string('invalidation_reason', 30)->nullable()->after('invalidated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_time_codes', function (Blueprint $table) {
            $table->dropColumn(['invalidated_at', 'invalidation_reason']);
        });
    }
};
