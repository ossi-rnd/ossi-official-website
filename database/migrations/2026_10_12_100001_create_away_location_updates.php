<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * While a guard is away from the post (an errand, an appointment), they
     * share where they are every few minutes. "Back at post" no longer has to
     * be inside the geofence; where it was tapped, and how far from the post,
     * is recorded instead.
     */
    public function up(): void
    {
        Schema::create('away_location_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('away_log_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_meters')->nullable();
            // From the post, when the post has coordinates.
            $table->unsignedInteger('distance_meters')->nullable();
            $table->timestamps();

            $table->index(['away_log_id', 'recorded_at']);
        });

        Schema::table('away_logs', function (Blueprint $table) {
            $table->unsignedInteger('returned_distance_meters')->nullable()->after('returned_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('away_logs', function (Blueprint $table) {
            $table->dropColumn('returned_distance_meters');
        });

        Schema::dropIfExists('away_location_updates');
    }
};
