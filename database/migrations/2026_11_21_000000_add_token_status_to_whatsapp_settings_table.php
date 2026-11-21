<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The state of each saved access token (REQ-WA-22, AC-WA-40, PH-WA-7), per environment:
     *
     * - {env}_token_status: the last confirmed result, "valid" or "invalid"; NULL until checked.
     * - {env}_token_status_started_at: when the attempt behind that result started, to
     *   the microsecond. Results are only applied in that order.
     * - {env}_token_check_failed_at: when a failed check, newer than the last confirmed
     *   result, started. A failed check never changes the confirmed state.
     *
     * {env}_token_checked_at keeps the time of the last confirmed check. No value of the
     * token, and nothing Meta answered, is stored.
     */
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            foreach (['test', 'production'] as $environment) {
                $table->string("{$environment}_token_status", 16)->nullable()->after("{$environment}_token_checked_at");
                $table->timestamp("{$environment}_token_status_started_at", 6)->nullable()->after("{$environment}_token_status");
                $table->timestamp("{$environment}_token_check_failed_at", 6)->nullable()->after("{$environment}_token_status_started_at");
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $columns = [];

            foreach (['test', 'production'] as $environment) {
                array_push(
                    $columns,
                    "{$environment}_token_status",
                    "{$environment}_token_status_started_at",
                    "{$environment}_token_check_failed_at",
                );
            }

            $table->dropColumn($columns);
        });
    }
};
