<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Load is now a loan instead of a GCash purchase: the employee requests
     * it, an Admin sends it and approves, and the amount is deducted from the
     * next payslip (an employee_deductions row of type "load"). No GCash QR,
     * reference number or receipt any more.
     *
     * Old requests: "load sent" becomes approved (they were paid by GCash
     * then, so no deduction is created for them); "payment confirmed" goes
     * back to pending for an Admin to decide.
     */
    public function up(): void
    {
        Schema::table('load_requests', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('employee_deduction_id')->nullable()->after('approved_at')->constrained()->nullOnDelete();
        });

        DB::table('load_requests')->where('status', 'completed')->update([
            'status' => 'approved',
            'approved_by' => DB::raw('completed_by'),
            'approved_at' => DB::raw('completed_at'),
        ]);
        DB::table('load_requests')->where('status', 'verified')->update(['status' => 'pending']);

        Schema::table('load_requests', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropForeign(['verified_by']);
            $table->dropForeign(['completed_by']);
        });

        Schema::table('load_requests', function (Blueprint $table) {
            $table->dropColumn(['reference_number', 'receipt_path', 'receipt_mime', 'verified_by', 'verified_at', 'completed_by', 'completed_at']);
        });

        DB::table('settings')->where('key', 'gcash')->delete();
    }

    public function down(): void
    {
        Schema::table('load_requests', function (Blueprint $table) {
            $table->string('reference_number', 20)->nullable()->unique();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_mime', 100)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('load_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['employee_deduction_id']);
            $table->dropColumn(['approved_by', 'approved_at', 'employee_deduction_id']);
        });
    }
};
