<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resignation reasons become a checklist: `reasons` holds every one ticked.
     * `reason` stays as the main one (a just cause if any is ticked, else the
     * first), so lists and older code keep working. Existing rows get their one.
     */
    public function up(): void
    {
        Schema::table('resignations', function (Blueprint $table) {
            $table->json('reasons')->nullable()->after('reason');
        });

        DB::table('resignations')->orderBy('id')->select(['id', 'reason'])->each(function (object $row) {
            DB::table('resignations')->where('id', $row->id)->update(['reasons' => json_encode([$row->reason])]);
        });
    }

    public function down(): void
    {
        Schema::table('resignations', function (Blueprint $table) {
            $table->dropColumn('reasons');
        });
    }
};
