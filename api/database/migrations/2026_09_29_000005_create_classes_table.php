<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* grade is a column, not derived from the stream name; class_teacher_id is
       the homeStream fact from fixtures/teachers.ts turned around, attention
       only, never a capability (#36). docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->string('grade');
            $table->string('stream');
            $table->foreignUuid('class_teacher_id')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
