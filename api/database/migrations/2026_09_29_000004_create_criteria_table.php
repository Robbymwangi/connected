<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One row per line of a subject's rubric (frontend/src/fixtures/rubrics.ts).
       docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('subject_id')->constrained('subjects');
            $table->string('name');
            $table->unsignedInteger('max_score');
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criteria');
    }
};
