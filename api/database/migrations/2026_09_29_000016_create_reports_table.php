<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One per student per assessment, matching results and comments: a term
       report card is simply the report for whichever assessment is named
       "End of Term," not a different kind of record. The row's existence is
       the generated report; generated_at and s3_key are set together by the
       job that creates it, not filled in later. docs/spec/data-model.md
       (#34). */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('student_id')->constrained('students');
            $table->timestamp('generated_at');
            $table->string('s3_key');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        // Live-row-only, same reason as results and comments: regenerating a
        // report after an unlock must not collide with the one it replaces.
        DB::statement(
            'create unique index reports_live_unique on reports '.
            '(assessment_id, student_id) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
