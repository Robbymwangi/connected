<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One row per line of a subject's rubric (frontend/src/fixtures/rubrics.ts).
       docs/spec/data-model.md (#34).

       max_score is declared unsignedInteger, but Postgres has no native
       unsigned integer type: Laravel's Postgres grammar silently drops the
       modifier and creates a plain integer, so nothing stops a negative or
       zero value without an explicit check. A rubric line worth zero or less
       is meaningless, so the constraint is > 0, not merely >= 0. */
    public function up(): void
    {
        Schema::create('criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('subject_id')->constrained('subjects');
            $table->string('name');
            $table->unsignedInteger('max_score');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement('alter table criteria add constraint criteria_max_score_positive check (max_score > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('criteria');
    }
};
