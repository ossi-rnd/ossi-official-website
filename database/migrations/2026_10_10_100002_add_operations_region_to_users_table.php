<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The region a Satellite Operation account covers (App\Enums\Region).
     * Set on Roles & Access; they see only guards deployed to that region's clients.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('operations_region', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('operations_region');
        });
    }
};
