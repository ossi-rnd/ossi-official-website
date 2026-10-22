<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "General office" (office_staff) is no longer a position: office jobs are
     * Admin, HR, Accounting, IT and Security Manager. Records that had it get
     * no position until HR picks the job; a company rate saved for it is
     * removed (payslips keep the rate they were generated with).
     */
    public function up(): void
    {
        DB::table('employee_profiles')->where('position', 'office_staff')->update(['position' => null]);
        DB::table('position_rates')->where('position', 'office_staff')->delete();
    }

    public function down(): void
    {
        // Not restorable: which records had it isn't kept.
    }
};
