<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 13th month pay (PD 851): one run per payout, computed from generated
     * payslips. A later run for the same year pays only the balance.
     */
    public function up(): void
    {
        Schema::create('thirteenth_month_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            // Payslips of periods ending on or before this date are counted.
            $table->date('covers_until');
            $table->date('pay_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('computed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('thirteenth_month_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thirteenth_month_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedSmallInteger('days_worked');
            $table->decimal('basic_pay', 12, 2);
            $table->decimal('late_deductions', 12, 2);
            // (basic pay − late deductions) ÷ 12, for the whole year so far.
            $table->decimal('earned', 12, 2);
            // Paid by earlier approved runs of the same year.
            $table->decimal('earlier_paid', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->json('cutoffs');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['thirteenth_month_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thirteenth_month_payouts');
        Schema::dropIfExists('thirteenth_month_runs');
    }
};
