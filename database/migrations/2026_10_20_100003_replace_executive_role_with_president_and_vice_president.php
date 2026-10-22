<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The company doesn't use the word "Executive": the role becomes two, President
     * and Vice President, with the same access (Role::leadership()). Each account with
     * the old role gets Vice President if their position is Vice President, else President.
     *
     * Also renamed where the role is stored: announcement audiences (both new roles),
     * and final pay signatures (the leadership step is kept under "president").
     * The audit log keeps its old wording: it's history.
     */
    public function up(): void
    {
        $holders = DB::table('user_roles')->where('role', 'executive')->pluck('user_id');

        foreach ($holders as $userId) {
            $position = DB::table('employee_profiles')->where('user_id', $userId)->value('position');
            DB::table('user_roles')->where('user_id', $userId)->where('role', 'executive')
                ->update(['role' => $position === 'vice_president' ? 'vice-president' : 'president']);
        }

        foreach (DB::table('announcements')->whereNotNull('audience')->get(['id', 'audience']) as $announcement) {
            $audience = json_decode((string) $announcement->audience, true);

            if (is_array($audience) && in_array('executive', $audience, true)) {
                $audience = array_values(array_unique([...array_diff($audience, ['executive']), 'president', 'vice-president']));
                DB::table('announcements')->where('id', $announcement->id)->update(['audience' => json_encode($audience)]);
            }
        }

        foreach (DB::table('final_pays')->whereNotNull('signoffs')->get(['id', 'signoffs']) as $finalPay) {
            $signoffs = json_decode((string) $finalPay->signoffs, true);

            if (is_array($signoffs) && array_key_exists('executive', $signoffs)) {
                $signoffs['president'] = $signoffs['executive'];
                unset($signoffs['executive']);
                DB::table('final_pays')->where('id', $finalPay->id)->update(['signoffs' => json_encode($signoffs)]);
            }
        }
    }

    public function down(): void
    {
        DB::table('user_roles')->whereIn('role', ['president', 'vice-president'])->update(['role' => 'executive']);
    }
};
