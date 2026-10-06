<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* One row is one sitting of one subject in one stream. term is a smallint
       1 to 3, not a table, for now (pre-migration decision 3); year alongside
       it.

       status stores only scheduled, finalized, and reports-generated: the
       three states something actually writes. "in-progress" and "complete"
       are not stored here. docs/spec/workflow.md (#37) is explicit that
       complete is derived from the grid's own completeness every time it's
       read, never a value a teacher or the API sets, and in-progress is the
       same kind of derived label describing partial completion. Storing them
       would give the API two ways to represent the same fact and no rule for
       which one is current.

       created_by is new against the original build-plan brief: #36 Option B
       scopes lifecycle actions (edit, finalize, unlock) to the creator or an
       assigned teacher, and there was nothing to check the creator against
       before this column. docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('class_id')->constrained('classes');
            $table->foreignUuid('subject_id')->constrained('subjects');
            $table->string('name');
            $table->unsignedTinyInteger('term');
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->enum('status', ['scheduled', 'finalized', 'reports-generated'])
                ->default('scheduled');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        // unsignedTinyInteger/unsignedSmallInteger are, like every "unsigned"
        // call in this migration set, silently plain integers on Postgres:
        // it has no native unsigned type, so the bound has to be an explicit
        // check. term matches pre-migration decision 3's 1-to-3 range
        // exactly, not just "not negative".
        DB::statement('alter table assessments add constraint assessments_term_in_range check (term between 1 and 3)');
        DB::statement('alter table assessments add constraint assessments_year_positive check (year > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
