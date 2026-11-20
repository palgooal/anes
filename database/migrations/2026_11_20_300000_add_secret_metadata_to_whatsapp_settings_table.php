<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the settings page shows instead of a secret (REQ-WA-18), and the
     * access token's expiry as reported by Meta (REQ-WA-21).
     *
     * secret_metadata: per secret column, {"last4", "updated_at", "updated_by"}. Never the value.
     */
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->json('secret_metadata')->nullable()->after('webhook_verify_token');

            foreach (['test', 'production'] as $environment) {
                $table->timestamp("{$environment}_token_expires_at")->nullable();
                $table->boolean("{$environment}_token_never_expires")->default(false);
                $table->string("{$environment}_token_expiry_source", 10)->nullable();
                $table->timestamp("{$environment}_token_checked_at")->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $columns = ['secret_metadata'];

            foreach (['test', 'production'] as $environment) {
                array_push(
                    $columns,
                    "{$environment}_token_expires_at",
                    "{$environment}_token_never_expires",
                    "{$environment}_token_expiry_source",
                    "{$environment}_token_checked_at",
                );
            }

            $table->dropColumn($columns);
        });
    }
};
