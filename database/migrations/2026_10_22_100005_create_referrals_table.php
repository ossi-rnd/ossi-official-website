<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referrals (hiring perks): an employee names someone to hire. HR links the record
     * once they're hired; the bonus (amount and days frozen at that moment) is paid on
     * the referrer's payslip once the hire has stayed that many days and still works here.
     */
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users');
            $table->string('applicant_name', 150);
            $table->string('applicant_phone', 20);
            $table->string('position', 40)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('submitted');
            $table->foreignId('employee_profile_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->decimal('bonus_amount', 10, 2)->nullable();
            $table->date('bonus_due_on')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_in_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
