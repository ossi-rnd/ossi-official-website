<?php

use App\Models\LeaveRequest;
use App\Support\LeaveRouting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guards' leave goes up a chain before HR decides it: the region's
     * Satellite Operation endorses to Operation HQ, which endorses to HR.
     *  - leave_requests.stage: where a pending request is waiting;
     *  - leave_endorsements: each endorsement (who, which step, endorse or
     *    not recommended, and why).
     * Requests already pending start at the beginning of their chain.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('stage', 20)->nullable()->after('status');
            $table->index(['status', 'stage']);
        });

        Schema::create('leave_endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20);
            $table->foreignId('endorsed_by')->constrained('users')->restrictOnDelete();
            $table->boolean('recommends');
            $table->string('note', 1000)->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'stage']);
        });

        LeaveRequest::query()->pending()->with('user.employeeProfile.client')->each(function (LeaveRequest $leave) {
            $leave->forceFill(['stage' => LeaveRouting::firstStage($leave->user)])->save();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_endorsements');

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'stage']);
            $table->dropColumn('stage');
        });
    }
};
