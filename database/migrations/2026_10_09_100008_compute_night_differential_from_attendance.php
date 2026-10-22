<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Night differential now comes from the hours actually worked 10 PM–6 AM
     * (attendance), not from a "night shift" switch on the employee record.
     * Payslips save the minutes behind it; generated payslips keep their amount.
     */
    public function up(): void
    {
        Schema::table('payslip_records', function (Blueprint $table) {
            $table->unsignedInteger('night_minutes')->default(0)->after('night_differential');
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('is_night_shift');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('is_night_shift')->default(false);
        });

        Schema::table('payslip_records', function (Blueprint $table) {
            $table->dropColumn('night_minutes');
        });
    }
};
