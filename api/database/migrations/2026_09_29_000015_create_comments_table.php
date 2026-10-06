<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One per student per assessment, dispatched on finalize
       (docs/spec/workflow.md, #37 owns the lifecycle; this migration only
       fixes the grain). docs/spec/data-model.md (#34). */
    public function up(): void
    {
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

        // Live-row-only, same reason as results: a fresh comment cycle after
        // an unlock must not collide with the superseded, soft-deleted one.
        DB::statement(
            'create unique index comments_live_unique on comments '.
            '(assessment_id, student_id) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
