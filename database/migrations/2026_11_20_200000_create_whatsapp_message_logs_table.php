<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per WhatsApp send attempt (docs/ANIS-WHATSAPP-SETTINGS-001.md, REQ-WA-48 to REQ-WA-51).
     *
     * send_status is Meta's answer to the request; delivery_status and its timestamps
     * are only filled later by the delivery webhook (PH-WA-8). Never stored: the code,
     * the access token, the request or response body, the full recipient number.
     */
    public function up(): void
    {
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->nullable();
            $table->string('purpose', 20);
            $table->foreignId('one_time_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_hash', 64)->index();
            $table->string('recipient_last4', 4);
            $table->string('template_name', 100)->nullable();
            $table->string('template_language', 15)->nullable();

            // Meta's answer to the send request (not delivery).
            $table->string('send_status', 20);
            $table->string('wamid', 128)->nullable()->unique();
            $table->string('error_code', 32)->nullable();
            $table->string('error_title', 190)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('attempted_at');

            // Actual delivery, reported later by the webhook (PH-WA-8).
            $table->string('delivery_status', 20)->nullable();
            $table->string('delivery_error_code', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
