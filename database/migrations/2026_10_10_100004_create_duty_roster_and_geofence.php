<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic schedules: guards don't keep one fixed shift, so Operations
     * rosters each day (duty_assignments). A clock-in is matched to that day's
     * duty, and late, undertime, overtime and night differential follow it.
     *
     *  - duty_assignments: one duty per guard per day (the day it starts),
     *    from a shift template, at a client and post. A relief duty points at
     *    the absence notice it covers.
     *  - clients get a geofence (coordinates + radius): punches outside it are refused.
     *  - time_logs remember the duty they were for, and who recorded them by
     *    hand (with a reason) when the phone or GPS failed.
     */
    public function up(): void
    {
        Schema::create('duty_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('duty_date');
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('post')->nullable();
            $table->foreignId('absence_notice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'duty_date']);
            $table->index('duty_date');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('site_address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('geofence_meters')->default(150)->after('longitude');
        });

        Schema::table('time_logs', function (Blueprint $table) {
            $table->foreignId('duty_assignment_id')->nullable()->after('shift_id')->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('manual_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duty_assignment_id');
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn('manual_reason');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geofence_meters']);
        });

        Schema::dropIfExists('duty_assignments');
    }
};
