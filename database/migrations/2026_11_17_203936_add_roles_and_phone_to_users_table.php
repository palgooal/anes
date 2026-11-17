<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beneficiaries (patients and companions) sign in with a phone number only,
     * so email and password become optional. Staff keep email + password.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->string('role')->default('patient')->after('name');
            $table->string('status')->default('active')->after('role');
            $table->string('phone', 10)->nullable()->unique()->after('email');
            $table->foreignId('patient_id')->nullable()->after('phone')->constrained('users')->nullOnDelete();
            $table->text('qualifications')->nullable()->after('patient_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rolling back drops the beneficiary data in these columns.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_id');
            $table->dropUnique(['phone']);
            $table->dropColumn(['role', 'status', 'phone', 'qualifications']);
        });
    }
};
