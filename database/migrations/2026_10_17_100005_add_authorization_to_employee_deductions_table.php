<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loans, cash advances and other deductions Accounting adds need a basis
     * (Labor Code Art. 113): the employee's written authorization, in the app or
     * on paper, or the law (SSS and Pag-IBIG salary loans).
     *
     *  - awaiting_authorization  asked in the app and not yet authorized: no payslip takes it
     *  - authorization           the sentence they authorized, word for word (or how it's authorized)
     *  - declined_at             they said they don't agree; Accounting is told
     *
     * Existing deductions keep working (awaiting false); they predate this and show as such.
     */
    public function up(): void
    {
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->boolean('awaiting_authorization')->default(false)->index();
            $table->string('authorization_basis', 20)->nullable();
            $table->text('authorization')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('declined_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->dropIndex(['awaiting_authorization']);
            $table->dropColumn(['awaiting_authorization', 'authorization_basis', 'authorization', 'authorized_at', 'declined_at']);
        });
    }
};
