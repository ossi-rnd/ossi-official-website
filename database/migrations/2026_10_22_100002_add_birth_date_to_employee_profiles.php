<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Date of birth, for the birthday reminder (and SSS / PhilHealth / Pag-IBIG forms).
     * It reveals age, sensitive under RA 10173 sec. 3(l), so it's encrypted like the
     * government IDs (text column, "Y-m-d" inside). birthday_private: the employee
     * objected to the reminder, so nobody is told (their right to object, sec. 16(b)).
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->text('birth_date')->nullable()->after('permanent_address');
            $table->boolean('birthday_private')->default(false)->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'birthday_private']);
        });
    }
};
