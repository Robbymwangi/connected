<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One row per student per year; chosen over a plain class_id on students
       for the years this school runs past a single cohort: a student's class
       in a prior year stays answerable without reconstructing it from marks.
       Unique on (student_id, year) assumes one class per student per year; a
       genuine mid-year transfer isn't modelled. docs/spec/data-model.md (#34),
       pre-migration decision 7. */
    public function up(): void
    {
        Schema::create('enrolments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('student_id')->constrained('students');
            $table->foreignUuid('class_id')->constrained('classes');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        // Live-row-only: a corrected enrolment (soft-deleted, re-created for
        // the same student and year) must not collide with its own history.
        DB::statement(
            'create unique index enrolments_live_unique on enrolments '.
            '(student_id, year) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolments');
    }
};
