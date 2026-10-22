<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tester without a real mailbox (pilot, 2026-10-21): HR ticks "Test account"
     * when adding them, and the record's email is made from the employee number
     * at test.invalid (never delivered). Registering confirms the email at once.
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('is_test_account')->default(false)->after('is_office_staff');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('is_test_account');
        });
    }
};
