<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One live row per stream per term, written by the term close and soft deleted by an unlock
       that unwinds it (docs/spec/workflow.md, Term close and Unlock). Synchronisable and pull-only,
       so a device knows the term is closed. docs/spec/data-model.md. */
    public function up(): void
    {
        Schema::create('term_closures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('class_id')->constrained('classes');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('term');
            $table->foreignUuid('closed_by')->constrained('users');
            $table->timestamp('closed_at');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement('create unique index term_closures_live_unique on term_closures (class_id, year, term) where deleted_at is null');
        DB::statement('alter table term_closures add constraint term_closures_term_in_range check (term between 1 and 3)');
        DB::statement('alter table term_closures add constraint term_closures_year_positive check (year > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('term_closures');
    }
};
