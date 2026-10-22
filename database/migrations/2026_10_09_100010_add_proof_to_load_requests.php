<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The approver attaches a screenshot of the load confirmation: the
     * employee's receipt (emailed and in the app) and proof behind the
     * payroll deduction.
     */
    public function up(): void
    {
        Schema::table('load_requests', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('employee_deduction_id');
            $table->string('proof_mime', 100)->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('load_requests', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_mime']);
        });
    }
};
