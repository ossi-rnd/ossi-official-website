<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A role set in advance by the Super Admin (Roles & Access) for someone HR added who
     * hasn't registered yet: roles belong to accounts, so it waits on the record and is
     * given to the account when they register (ClaimEmployeeAccount). The region goes
     * with a Satellite Operation.
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('pending_role', 40)->nullable()->after('is_test_account');
            $table->string('pending_region', 20)->nullable()->after('pending_role');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['pending_role', 'pending_region']);
        });
    }
};
