<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrections to a payslip are their own audited lines, never an edit of
     * the calculated figures: every peso still traces back to attendance,
     * rates, a loan, or an adjustment with a reason and a name.
     */
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('category', 20);
            // Signed: positive adds to pay, negative is deducted.
            $table->decimal('amount', 10, 2);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'user_id', 'status']);
        });

        Schema::table('payslip_records', function (Blueprint $table) {
            $table->decimal('adjustment_earnings', 10, 2)->default(0)->after('shortfall_lines');
            $table->decimal('adjustment_deductions', 10, 2)->default(0)->after('adjustment_earnings');
            $table->json('adjustment_lines')->nullable()->after('adjustment_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payslip_records', function (Blueprint $table) {
            $table->dropColumn(['adjustment_earnings', 'adjustment_deductions', 'adjustment_lines']);
        });

        Schema::dropIfExists('payroll_adjustments');
    }
};
