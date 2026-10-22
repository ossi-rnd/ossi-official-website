<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "I need help now" on a complaint (roadmap 31): the handler is alerted at
     * once by bell and email, and the case goes to the top of their list. Never
     * counted against the weekly limit.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->boolean('asks_for_help')->default(false)->after('for_executive');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('asks_for_help');
        });
    }
};
