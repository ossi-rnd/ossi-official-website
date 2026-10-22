<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who sits on the Board of Directors is now a flag on the employee record
     * (HR ticks it), not "has the Executive role": the role is app access, and
     * an approver isn't necessarily a director.
     *
     * "Executive" is no longer a position: it isn't a job. The jobs are President
     * and Vice President. A record that had it becomes President when nobody is
     * yet (its history and company rate too), otherwise no position until HR picks.
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('is_board_member')->default(false)->after('is_office_staff');
        });

        // Keep today's Board: everyone with the Executive role.
        $executives = DB::table('user_roles')->where('role', 'executive')->pluck('user_id');
        DB::table('employee_profiles')->whereIn('user_id', $executives)->update(['is_board_member' => true]);

        $hasPresident = DB::table('employee_profiles')->where('position', 'president')->exists();
        $first = DB::table('employee_profiles')->where('position', 'executive')->orderBy('id')->value('id');

        if (! $hasPresident && $first !== null) {
            DB::table('employee_profiles')->where('id', $first)->update(['position' => 'president']);
            DB::table('position_changes')->where('employee_profile_id', $first)->where('to_position', 'executive')->update(['to_position' => 'president']);
            DB::table('position_changes')->where('employee_profile_id', $first)->where('from_position', 'executive')->update(['from_position' => 'president']);
        }
        DB::table('employee_profiles')->where('position', 'executive')->update(['position' => null]);
        DB::table('position_changes')->where('from_position', 'executive')->update(['from_position' => null]);
        DB::table('position_changes')->where('to_position', 'executive')->delete();

        // Its company rate goes to President if President has none; payslips keep the rate they were generated with.
        if (! DB::table('position_rates')->where('position', 'president')->exists()) {
            DB::table('position_rates')->where('position', 'executive')->update(['position' => 'president']);
        }
        DB::table('position_rates')->where('position', 'executive')->delete();
    }

    public function down(): void
    {
        // Positions aren't restorable (which records had "Executive" isn't kept).
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('is_board_member');
        });
    }
};
