<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The radio status "Retired" is now "Disposed" (sold, scrapped or returned): clearer for
     * staff, and it says what matters to the NTC, that the unit is no longer the agency's.
     */
    public function up(): void
    {
        DB::table('radios')->where('status', 'retired')->update(['status' => 'disposed']);
    }

    public function down(): void
    {
        DB::table('radios')->where('status', 'disposed')->update(['status' => 'retired']);
    }
};
