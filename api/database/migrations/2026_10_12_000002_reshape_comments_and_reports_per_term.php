<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* ADR 0012: a comment and a report are per student per term, not per assessment, so both
       tables lose assessment_id and gain year and term, with one live row per (student_id, year,
       term). A comment is created pending by the term close, with no author and no body until a
       person acts or text arrives, so both become nullable, and its states gain pending and failed.
       A comment also records the term close that created it, so an unlock unwinds exactly that
       close's comments even for a student whose enrolment has since moved to another stream.
       Nothing has ever written either table, so they are dropped and recreated rather than altered
       column by column; DEV_DATA_EPOCH rebuilds dev databases. docs/spec/data-model.md. */
    public function up(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('comments');

        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('student_id')->constrained('students');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('term');
            $table->foreignUuid('term_closure_id')->constrained('term_closures');
            $table->foreignUuid('author_id')->nullable()->constrained('users');
            $table->text('body')->nullable();
            $table->enum('state', ['pending', 'draft', 'accepted', 'failed']);
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('student_id')->constrained('students');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('term');
            $table->timestamp('generated_at');
            $table->string('s3_key');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement('create index comments_term_closure_id_index on comments (term_closure_id)');

        foreach (['comments', 'reports'] as $name) {
            // Live-row-only: a cycle recreated after an unlock inserts new rows beside the
            // soft-deleted history (docs/spec/workflow.md, Unlock).
            DB::statement("create unique index {$name}_live_unique on {$name} (student_id, year, term) where deleted_at is null");
            DB::statement("alter table {$name} add constraint {$name}_term_in_range check (term between 1 and 3)");
            DB::statement("alter table {$name} add constraint {$name}_year_positive check (year > 0)");
        }

        // Generated text arrives with draft; only pending is allowed to have none yet, and failed
        // may have none because the teacher writes their own.
        DB::statement("alter table comments add constraint comments_body_present check (body is not null or state in ('pending', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('comments');

        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('student_id')->constrained('students');
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->enum('state', ['draft', 'accepted']);
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

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

        DB::statement('create unique index comments_live_unique on comments (assessment_id, student_id) where deleted_at is null');
        DB::statement('create unique index reports_live_unique on reports (assessment_id, student_id) where deleted_at is null');
    }
};
