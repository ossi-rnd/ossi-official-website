<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IDs beyond SSS, PhilHealth, Pag-IBIG and TIN (driver's license, passport,
     * UMID, a security guard license…): a list of {label, number}, encrypted
     * at rest like the four government IDs (cast encrypted:array).
     */
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->text('other_ids')->nullable()->after('tin_number');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('other_ids');
        });
    }
};
