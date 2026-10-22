<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pay that changed after a payslip was frozen (a day recorded by hand, overtime
     * approved late, a back-dated rate…), found by recomputing earlier cut-offs and
     * suggested on the open run. Accounting accepts it (it becomes a Correction
     * adjustment on this payslip) or dismisses it with a reason.
     *
     *  - payroll_run_id       the open run it's suggested on (and paid in, once accepted)
     *  - from_payroll_run_id  the earlier cut-off whose pay changed
     *  - amount               signed: + owed to the employee, − overpaid
     *  - differences          what differs: {"days": [5, 6], "overtime_minutes": [0, 120], ...}
     */
    public function up(): void
    {
        Schema::create('prior_period_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->json('differences');
            $table->string('status', 20)->default('suggested');
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('dismiss_reason')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'status']);
            $table->index(['user_id', 'from_payroll_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prior_period_corrections');
    }
};
