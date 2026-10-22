<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Board of Directors on the Organization chart is the President and the
     * Vice President, by role (company decision, 2026-10-21). The "Member of the
     * Board" tick on employee records goes: nobody knew when to tick it.
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('is_board_member');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('is_board_member')->default(false)->after('is_office_staff');
        });
    }
};
