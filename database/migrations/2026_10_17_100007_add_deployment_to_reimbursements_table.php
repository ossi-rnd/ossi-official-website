<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deployment costs (roadmap 5). Operations files a claim for a guard who paid for
     * a deployment (a fare to a far post, a reliever's ride): user_id is the guard,
     * who's paid back on their payslip; filed_by is Operations, who vouches for it.
     * deployment_for says which deployment (the guard's post, or what Operations staff
     * write for their own "Deployment" costs).
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->foreignId('filed_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('deployment_for')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filed_by');
            $table->dropColumn('deployment_for');
        });
    }
};
