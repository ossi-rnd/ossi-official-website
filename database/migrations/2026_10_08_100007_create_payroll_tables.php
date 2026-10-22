<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money is decimal(10,2), never float: a float column cannot store 0.10 exactly.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        Schema::create('payslip_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained();
            $table->foreignId('user_id')->constrained();

            // A frozen copy of every input and line, so the payslip never changes after generation.
            $table->string('rate_schedule', 10);
            $table->boolean('is_office_staff');
            $table->unsignedSmallInteger('days_worked');
            $table->unsignedInteger('late_minutes');
            $table->unsignedInteger('overtime_minutes');
            $table->decimal('daily_rate', 10, 2);

            $table->decimal('basic_pay', 10, 2);
            $table->decimal('holiday_pay', 10, 2);
            $table->decimal('overtime_pay', 10, 2);
            $table->decimal('night_differential', 10, 2);
            $table->decimal('gross_pay', 10, 2);

            $table->decimal('sss', 10, 2);
            $table->decimal('philhealth', 10, 2);
            $table->decimal('pagibig', 10, 2);
            $table->decimal('withholding_tax', 10, 2);
            $table->decimal('late_deduction', 10, 2);
            $table->decimal('loan_deductions', 10, 2);
            $table->json('loan_lines')->nullable();
            $table->decimal('total_deductions', 10, 2);
            $table->decimal('net_pay', 10, 2);

            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Generating twice for the same run and employee is impossible, even under a race.
            $table->unique(['payroll_run_id', 'user_id']);
        });

        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('label');
            $table->string('type', 20);
            $table->decimal('installment_amount', 10, 2);
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('remaining_balance', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::create('late_deduction_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_minutes');
            // null = no upper limit ("and above").
            $table->unsignedInteger('max_minutes')->nullable();
            $table->decimal('deduction_amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_deduction_rules');
        Schema::dropIfExists('employee_deductions');
        Schema::dropIfExists('payslip_records');
        Schema::dropIfExists('payroll_runs');
    }
};
