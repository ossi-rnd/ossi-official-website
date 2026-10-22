<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Super Admin, President and Vice President aren't employees here (company
     * decision, 2026-10-21): no HR record, no employee number. Their HR records are removed,
     * but only where nothing hangs on them (no attendance, no payslip); any other is left
     * for HR to look at, and reported here.
     */
    public function up(): void
    {
        $userIds = DB::table('user_roles')->whereIn('role', ['super-admin', 'president', 'vice-president'])->pluck('user_id')->unique();

        foreach ($userIds as $userId) {
            $profileId = DB::table('employee_profiles')->where('user_id', $userId)->value('id');

            if ($profileId === null) {
                continue;
            }

            $history = DB::table('time_logs')->where('user_id', $userId)->exists()
                || DB::table('payslip_records')->where('user_id', $userId)->exists();

            if ($history) {
                echo "  Kept the HR record of user #{$userId}: it has attendance or payslips.\n";

                continue;
            }

            // Its 201 checklist, rates, position history and training go with it (cascade).
            DB::table('employee_profiles')->where('id', $profileId)->delete();
        }
    }

    public function down(): void
    {
        // Not restorable: the removed records held no attendance or pay.
    }
};
