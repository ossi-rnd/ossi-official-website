<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Net pay never goes below ₱0. When a payslip can't cover a deduction
     * (one day worked, but the full SSS + PhilHealth + Pag-IBIG are due), the
     * unpaid part becomes a carry-over that the NEXT generated payslip deducts
     * first. A new migration, not an edit of the payroll one: existing
     * databases only need `php artisan migrate`.
     */
    public function up(): void
    {
        Schema::create('payroll_carryovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('from_payroll_run_id')->constrained('payroll_runs');
            // e.g. "PhilHealth (from Oct 1–15, 2026)": shown as-is on the next payslip.
            $table->string('label');
            $table->decimal('amount', 10, 2);
            $table->foreignId('settled_in_payroll_run_id')->nullable()->constrained('payroll_runs');
            $table->timestamps();

            $table->index(['user_id', 'settled_in_payroll_run_id']);
        });

        Schema::table('payslip_records', function (Blueprint $table) {
            $table->decimal('carryover_deductions', 10, 2)->default(0)->after('loan_lines');
            $table->json('carryover_lines')->nullable()->after('carryover_deductions');
            $table->json('shortfall_lines')->nullable()->after('carryover_lines');
        });
    }

    public function down(): void
    {
        Schema::table('payslip_records', function (Blueprint $table) {
            $table->dropColumn(['carryover_deductions', 'carryover_lines', 'shortfall_lines']);
        });

        Schema::dropIfExists('payroll_carryovers');
    }
};
