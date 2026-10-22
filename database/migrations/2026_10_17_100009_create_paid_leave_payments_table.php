<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave the law pays, suggested on each payroll run for Accounting to accept
     * (→ a "Paid leave" adjustment on that payslip) or dismiss with a reason:
     *
     *  - days        the leave days in that cut-off, capped by what the law gives
     *                (less what earlier runs already paid); Accounting can lower it
     *  - amount      days × daily rate; null for maternity (the SSS benefit, which
     *                Accounting enters)
     *
     * One per leave and run: decided once, never suggested again.
     */
    public function up(): void
    {
        Schema::create('paid_leave_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days');
            $table->decimal('daily_rate', 10, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('status', 20)->default('suggested')->index();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('dismiss_reason')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'payroll_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_payments');
    }
};
