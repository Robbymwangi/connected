<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One row per student per assessment, matching fixtures/results.ts's
       ResultRecord exactly; written by the server when an assessment
       finalizes. docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('student_id')->constrained('students');
            $table->unsignedInteger('total');
            $table->unsignedInteger('max');
            $table->enum('level', ['EE', 'ME', 'AE', 'BE']);
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        // Live-row-only: regenerating a result after an unlock (the old row
        // superseded, soft-deleted, per docs/spec/workflow.md, #37) must not
        // collide with the row it's replacing.
        DB::statement(
            'create unique index results_live_unique on results '.
            '(assessment_id, student_id) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
