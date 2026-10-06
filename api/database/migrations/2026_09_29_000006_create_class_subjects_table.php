<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Which subjects a class offers; CreateAssessmentDialog.tsx's class picker
       already filters on this. docs/spec/data-model.md (#34).

       Uniqueness is a partial index over live rows only, not a plain unique
       constraint: a plain one would keep counting a soft-deleted row, so
       removing a subject from a class and then re-adding it would collide
       with its own soft-deleted history instead of succeeding with a fresh
       id, the way every other row in this schema is created. */
    public function up(): void
    {
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('class_id')->constrained('classes');
            $table->foreignUuid('subject_id')->constrained('subjects');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement(
            'create unique index class_subjects_live_unique on class_subjects '.
            '(class_id, subject_id) where deleted_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
    }
};
