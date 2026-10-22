<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resignation for a just cause (Art. 300(b)) is also an accusation against
     * the company or someone acting for it, so filing one opens a complaint case
     * about it: the same handler, and closing it needs a written outcome.
     * resignation_id links the two.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('resignation_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resignation_id');
        });
    }
};
