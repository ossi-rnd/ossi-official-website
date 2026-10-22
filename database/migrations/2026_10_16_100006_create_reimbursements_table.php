<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reimbursements (roadmap item 25), office staff only: money an employee spent
     * for the company (a fare to SSS, a notarial fee), with the receipt. The Executive
     * approves it; the next payslip pays it back as a non-taxable line.
     *
     *  - away_log_id            the Office Away trip it came from, if any
     *  - payroll_adjustment_id  the payslip line that paid it (paid_in_run_id: which run)
     */
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->date('spent_on');
            $table->decimal('amount', 10, 2);
            $table->text('purpose');
            $table->foreignId('away_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path');
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime', 50);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_in_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
