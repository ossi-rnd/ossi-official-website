<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * From the 2026 schedule, contributions are computed, not fixed. Each
     * payslip freezes how: the bases, the employee AND employer shares (for
     * the SSS, PhilHealth and Pag-IBIG remittance reports), the SSS MSC, and
     * whether it was the month's last cutoff. Older payslips keep null.
     */
    public function up(): void
    {
        Schema::table('payslip_records', function (Blueprint $table) {
            $table->json('contributions')->nullable()->after('adjustment_lines');
        });
    }

    public function down(): void
    {
        Schema::table('payslip_records', function (Blueprint $table) {
            $table->dropColumn('contributions');
        });
    }
};
