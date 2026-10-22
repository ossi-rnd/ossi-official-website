<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merits and demerits (roadmap item 21).
     *
     *  - merit_types: the list HR keeps, each with its points, so the same act gets
     *    the same points whoever records it. Starts with a few common ones.
     *  - merit_records: one per merit or demerit given. Points are signed (+ merit,
     *    − demerit) and copied from the type when given, so changing a type later
     *    doesn't rewrite history. A demerit can be answered by the employee until
     *    reply_until. Never deleted: voided with a reason.
     */
    public function up(): void
    {
        Schema::create('merit_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 10); // merit | demerit
            $table->unsignedSmallInteger('points');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('merit_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merit_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);
            $table->string('title', 120);
            $table->smallInteger('points');
            $table->text('reason');
            $table->date('occurred_on');
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reply_until')->nullable();
            $table->text('employee_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
        });

        $now = now();
        DB::table('merit_types')->insert(array_map(fn (array $type) => [...$type, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], [
            ['name' => 'Commended by the client', 'kind' => 'merit', 'points' => 5, 'description' => 'A written or reported commendation from the client.'],
            ['name' => 'Prevented an incident or loss', 'kind' => 'merit', 'points' => 10, 'description' => 'Stopped a theft, intrusion or accident.'],
            ['name' => 'Covered a post on short notice', 'kind' => 'merit', 'points' => 2, 'description' => 'Went as a reliever when asked.'],
            ['name' => 'Excellent inspection', 'kind' => 'merit', 'points' => 2, 'description' => 'Post, uniform and logbook in order on inspection.'],
            ['name' => 'Out of uniform', 'kind' => 'demerit', 'points' => 1, 'description' => 'Incomplete or improper uniform on duty.'],
            ['name' => 'Logbook not updated', 'kind' => 'demerit', 'points' => 1, 'description' => 'Entries missing or late.'],
            ['name' => 'Left post without notice', 'kind' => 'demerit', 'points' => 3, 'description' => 'Away from the post without logging it.'],
            ['name' => 'Discourteous to the client or public', 'kind' => 'demerit', 'points' => 3, 'description' => 'A complaint about behaviour.'],
            ['name' => 'Sleeping on post', 'kind' => 'demerit', 'points' => 5, 'description' => 'Serious: consider a Notice to Explain.'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('merit_records');
        Schema::dropIfExists('merit_types');
    }
};
