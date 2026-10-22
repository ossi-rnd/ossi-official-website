<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Common complaint subjects per category, kept by HR (Complaints → Subjects):
     * the employee picks one, or "Other" and types it. A complaint copies the
     * subject's words (so renaming one doesn't rewrite history) and keeps the link,
     * so HR can see which problems keep coming back.
     *
     * The starting list is a suggestion for HR to change, not OSSI's own.
     */
    public function up(): void
    {
        Schema::create('complaint_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('category', 30);
            $table->string('label', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('complaint_subject_id')->nullable()->after('category')->constrained()->nullOnDelete();
        });

        $starting = [
            'conduct' => ['Shouted at or insulted', 'Treated unfairly by a supervisor', 'A co-worker not doing their part', 'Asked to do something against the rules'],
            'harassment' => ['Sexual harassment', 'Bullying or threats', 'Discrimination (gender, age, religion…)'],
            'pay_and_benefits' => ['Missing pay or days', 'Wrong deduction', 'Overtime not paid', 'Salary paid late', 'SSS, PhilHealth or Pag-IBIG not remitted'],
            'safety' => ['No relief guard', 'Equipment or firearm not working', 'Threat or danger at the post', 'No water, shelter or restroom at the post'],
            'assignment' => ['Schedule changed without notice', 'Too many shifts in a row', 'Reassigned without a reason'],
        ];

        $now = now();
        foreach ($starting as $category => $labels) {
            foreach ($labels as $sort => $label) {
                DB::table('complaint_subjects')->insert(['category' => $category, 'label' => $label, 'is_active' => true, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('complaint_subject_id');
        });

        Schema::dropIfExists('complaint_subjects');
    }
};
