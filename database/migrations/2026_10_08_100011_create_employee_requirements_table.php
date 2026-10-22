<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (employee, 201 document) that has had any activity. A
     * requirement with no row is simply "missing", so a new employee doesn't
     * need 21 placeholder rows.
     *
     * Keyed to employee_profiles, not users: HR builds the 201 file before
     * the employee has an account.
     */
    public function up(): void
    {
        Schema::create('employee_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('requirement', 40);
            $table->string('status', 20)->default('missing');

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();

            $table->date('expires_on')->nullable()->index();
            $table->date('promised_on')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_profile_id', 'requirement']);
            $table->index(['status', 'promised_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_requirements');
    }
};
