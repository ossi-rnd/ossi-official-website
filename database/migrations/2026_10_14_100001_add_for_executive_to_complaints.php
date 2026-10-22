<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A complaint filed by someone in HR goes to the Executive, never to HR
     * (handle-hr-complaints). Set when it is filed, so a later role change
     * doesn't hand an HR member's complaint to their colleagues.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->boolean('for_executive')->default(false)->after('user_id');
        });

        // Complaints already filed by HR staff.
        DB::table('complaints')
            ->whereIn('user_id', DB::table('user_roles')->where('role', 'hr')->select('user_id'))
            ->update(['for_executive' => true]);
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('for_executive');
        });
    }
};
