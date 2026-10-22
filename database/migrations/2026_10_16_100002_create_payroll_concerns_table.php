<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Report a problem" on a payslip: the employee says what's wrong (a missing
     * day, overtime, a deduction…), optionally with a photo, and Accounting is
     * told at once. Accounting answers: added to a payslip (a Correction
     * adjustment on an open run), explained (no change), or not valid.
     *
     *  - payroll_run_id         the payslip it's about
     *  - attachment_*           a photo or PDF on the private disk (payroll.concerns.disk)
     *  - payroll_adjustment_id  the fix, when it's paid
     */
    public function up(): void
    {
        Schema::create('payroll_concerns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->text('details');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 50)->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->text('response')->nullable();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'payroll_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_concerns');
    }
};
