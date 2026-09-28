<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Replaces the frontend's subjectsByStream JSON with a real, queryable
       table: the fact that scopes assessment lifecycle actions (edit, finalize,
       unlock) to a teacher who teaches that class and subject (#36 Option B).
       Never gates grading or assessment creation, which stay unrestricted.
       docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('class_id')->constrained('classes');
            $table->foreignUuid('subject_id')->constrained('subjects');
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        // Live-row-only uniqueness: revoking then re-granting the same
        // assignment must succeed with a fresh id, not collide with a
        // soft-deleted row occupying the same combination.
        DB::statement(
            'create unique index teacher_assignments_live_unique on teacher_assignments '.
            '(user_id, class_id, subject_id) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_assignments');
    }
};
