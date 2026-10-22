<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Petty cash, the imprest way: a fund of a fixed amount kept by a custodian. Each
     * expense is logged with its receipt; the cash on hand is the fund less the expenses
     * not yet replenished. The custodian asks Accounting to replenish what was spent,
     * and once Accounting releases it the fund is back to its full amount.
     */
    public function up(): void
    {
        Schema::create('petty_cash_funds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('custodian_id')->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('petty_cash_replenishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_fund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['petty_cash_fund_id', 'status']);
        });

        Schema::create('petty_cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_fund_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on');
            $table->decimal('amount', 12, 2);
            $table->string('paid_to', 150);
            $table->string('purpose', 500);
            $table->string('receipt_path');
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime', 100);
            $table->foreignId('recorded_by')->constrained('users');
            // Set when it's put in a replenishment request; cleared again if that request is rejected.
            $table->foreignId('petty_cash_replenishment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['petty_cash_fund_id', 'petty_cash_replenishment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_expenses');
        Schema::dropIfExists('petty_cash_replenishments');
        Schema::dropIfExists('petty_cash_funds');
    }
};
