<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deployment claim for a reliever's fare points at the relief it came from
     * (the absence notice they covered), so the relief shows "Fare claimed" and
     * the same relief's fare can't be claimed twice.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->foreignId('absence_notice_id')->nullable()->after('deployment_for')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('absence_notice_id');
        });
    }
};
