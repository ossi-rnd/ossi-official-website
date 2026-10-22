<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof that a guard is working between clock-in and clock-out.
     *
     *  - patrol_checkpoints: QR stickers at a client's site (Gate 1, Parking B2…).
     *    `code` is the random value printed in the QR; coordinates, when set,
     *    must be within reach of the scan.
     *  - clients.patrol_interval_minutes: how often a full round is due.
     *  - duty_assignments.patrols: this duty is a roving/patrol duty.
     *  - patrol_scans: each scan, with time, GPS and the camera frame.
     *  - away_logs: a guard leaving the post and coming back, with the reason.
     */
    public function up(): void
    {
        Schema::create('patrol_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('patrol_interval_minutes')->default(120)->after('geofence_meters');
        });

        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->boolean('patrols')->default(false)->after('post');
        });

        Schema::create('patrol_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patrol_checkpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_log_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scanned_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->string('photo_path');
            $table->timestamps();

            $table->index(['time_log_id', 'scanned_at']);
        });

        Schema::create('away_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_log_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 30);
            $table->string('note', 500)->nullable();
            $table->timestamp('left_at');
            $table->decimal('left_latitude', 10, 7)->nullable();
            $table->decimal('left_longitude', 10, 7)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->decimal('returned_latitude', 10, 7)->nullable();
            $table->decimal('returned_longitude', 10, 7)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('away_logs');
        Schema::dropIfExists('patrol_scans');

        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropColumn('patrols');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('patrol_interval_minutes');
        });

        Schema::dropIfExists('patrol_checkpoints');
    }
};
