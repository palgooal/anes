<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp Cloud API settings managed from the database (REQ-WA-01).
     *
     * A single row with one group of columns per environment (test, production).
     * While the table is empty, config/services.php (.env) is used as before.
     * Secrets are stored encrypted (text columns, "encrypted" cast).
     */
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('singleton')->default(true)->unique();
            $table->string('mode', 20)->default('disabled');
            $table->string('api_version', 10)->nullable();

            foreach (['test', 'production'] as $environment) {
                $table->string("{$environment}_phone_number_id", 32)->nullable();
                $table->string("{$environment}_waba_id", 32)->nullable();
                $table->string("{$environment}_display_number", 20)->nullable();
                $table->text("{$environment}_access_token")->nullable();
                $table->string("{$environment}_template_name", 100)->nullable();
                $table->string("{$environment}_template_language", 15)->nullable();
                $table->text("{$environment}_app_secret")->nullable();
            }

            $table->text('webhook_verify_token')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
    }
};
