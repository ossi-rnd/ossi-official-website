<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR creates the profile FIRST (user_id null). The employee later registers with
     * the employee number and the email HR recorded, which links the two.
     */
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();

            $table->string('employee_number', 50)->unique();
            $table->string('name');
            $table->string('email')->unique();

            $table->string('detachment')->nullable();
            $table->string('post')->nullable();
            $table->string('position', 40)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('employment_status', 20)->default('probationary')->index();

            $table->string('contact_number', 50)->nullable();
            $table->string('permanent_address')->nullable();

            // Sensitive personal information (RA 10173 sec. 3(l)): `text` because the model encrypts them.
            $table->text('sss_number')->nullable();
            $table->text('philhealth_number')->nullable();
            $table->text('pagibig_number')->nullable();
            $table->text('tin_number')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->string('blood_type', 5)->nullable();

            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();

            // Explicit payroll flags instead of guessing from roles or shift times.
            $table->boolean('is_office_staff')->default(false);
            $table->boolean('is_night_shift')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
