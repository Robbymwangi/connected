<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* A table, not the frontend's fixed three-item list: criteria and the
       subject_moderations grants both reference it, and a school's subject list
       is exactly the kind of thing an institution should be able to grow.
       docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->string('name');
            $table->unsignedInteger('version')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
