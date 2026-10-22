<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guard who neither clocks in nor reports is caught by the scheduler
     * (DetectNoShows) and gets an absence notice of source "no_show", so the
     * post goes on the Relievers board like a reported absence.
     *
     *  - source: guard (filed by the guard) | no_show (found by the system)
     *  - duty_assignment_id: the rostered duty that was missed
     *  - arrived_at: the guard clocked in after all (late)
     */
    public function up(): void
    {
        Schema::table('absence_notices', function (Blueprint $table) {
            $table->string('source', 20)->default('guard')->after('reason');
            $table->foreignId('duty_assignment_id')->nullable()->after('post')->constrained()->nullOnDelete();
            $table->timestamp('arrived_at')->nullable()->after('cover_note');

            $table->index(['user_id', 'duty_date']);
        });
    }

    public function down(): void
    {
        Schema::table('absence_notices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'duty_date']);
            $table->dropConstrainedForeignId('duty_assignment_id');
            $table->dropColumn(['source', 'arrived_at']);
        });
    }
};
