<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Final pay for someone who resigned, and the cash bond it refunds.
     *
     *  - resignations.deduction_consent_at: the employee's written consent (Labor Code
     *    Art. 113) to deduct what they still owe from their final pay and cash bond
     *  - cash_bond_entries: what payslips don't show. The ₱100 a cut-off is a deduction
     *    line on each payslip (type cash_bond); this ledger holds the balance paid in
     *    before the system (opening), corrections, and the refund in final pay
     *  - final_pays: the frozen statement (lines), its totals, the 13th month it paid
     *    (so December's run doesn't pay it again), and the five sign-offs
     *    {role: {by, by_id, at}}: Operation HQ, Admin, HR, Accounting, then the Executive
     */
    public function up(): void
    {
        Schema::table('resignations', function (Blueprint $table) {
            $table->timestamp('deduction_consent_at')->nullable()->after('asks_waiver');
        });

        Schema::create('cash_bond_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // opening | adjustment | refund
            $table->decimal('amount', 10, 2); // + paid in, − paid out
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('final_pay_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });

        Schema::create('final_pays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resignation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->json('lines')->nullable();
            $table->json('adjustments')->nullable();
            $table->decimal('total_pay', 10, 2)->default(0);
            $table->decimal('total_deducted', 10, 2)->default(0);
            $table->decimal('net', 10, 2)->default(0);
            $table->decimal('receivable', 10, 2)->default(0);
            $table->unsignedSmallInteger('thirteenth_year')->nullable();
            $table->decimal('thirteenth_amount', 10, 2)->default(0);
            $table->decimal('cash_bond_refund', 10, 2)->default(0);
            $table->json('signoffs')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('return_note')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_pays');
        Schema::dropIfExists('cash_bond_entries');

        Schema::table('resignations', function (Blueprint $table) {
            $table->dropColumn('deduction_consent_at');
        });
    }
};
