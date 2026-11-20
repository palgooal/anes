<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail of changes to the WhatsApp settings
     * (docs/ANIS-WHATSAPP-SETTINGS-001.md, REQ-WA-44 to REQ-WA-47).
     *
     * "changes" holds old and new values of non-secret fields only; a replaced
     * secret is recorded as a flag plus its last 4 characters. Never a secret value.
     */
    public function up(): void
    {
        Schema::create('whatsapp_settings_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('environment', 20)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings_audits');
    }
};
