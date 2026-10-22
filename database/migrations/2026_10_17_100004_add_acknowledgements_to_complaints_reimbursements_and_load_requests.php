<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the employee confirmed before sending, saved word for word with the time
     * (as on resignations), so each record shows exactly what they agreed to even if
     * the wording changes later:
     *
     *  - complaints: it's true, the person involved may be told (due process), no retaliation
     *  - reimbursements: the receipt is genuine and not claimed twice
     *  - load_requests: the written authorization (Labor Code Art. 113) to deduct it from pay
     */
    public function up(): void
    {
        foreach (['complaints', 'reimbursements'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('acknowledgement')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
            });
        }

        Schema::table('load_requests', function (Blueprint $table) {
            $table->string('deduction_authorization')->nullable();
            $table->timestamp('authorized_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['complaints', 'reimbursements'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['acknowledgement', 'acknowledged_at']);
            });
        }

        Schema::table('load_requests', function (Blueprint $table) {
            $table->dropColumn(['deduction_authorization', 'authorized_at']);
        });
    }
};
