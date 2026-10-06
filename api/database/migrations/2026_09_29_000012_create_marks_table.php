<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* id is a deterministic UUIDv5 of (assessment_id, student_id, criterion_id),
       computed identically on the device and the server, not a fresh UUID per
       row: two devices creating the same cell offline collide as an ordinary
       stale-version conflict rather than producing two rows. That means the
       primary key itself is the uniqueness constraint on the triple; no
       separate unique index is added here (docs/spec/data-model.md, #34,
       Grains and identity). Generating the id is a model-layer concern for
       #40, not this migration: see Mark::MARK_UUID_NAMESPACE and
       Mark::newUniqueId() in app/Models/Mark.php for the fixed namespace and
       the derivation itself.

       score is null unless mark_kind is score, enforced with a check
       constraint since it's a row-local rule. "At most the criterion's
       max_score" is not: that needs a join to criteria, which a single-table
       check constraint can't express, so it's left as an application-layer
       validation for a later ticket (#40 models, #49 write policies), not
       attempted here. */
    public function up(): void
    {
        Schema::create('marks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('student_id')->constrained('students');
            $table->foreignUuid('criterion_id')->constrained('criteria');
            $table->enum('mark_kind', ['empty', 'score', 'absent']);
            $table->unsignedInteger('score')->nullable();
            $table->foreignUuid('last_edited_by')->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement(
            'alter table marks add constraint marks_score_matches_kind '.
            "check ((mark_kind = 'score') = (score is not null))"
        );

        // unsignedInteger is silently a plain integer on Postgres, same as
        // everywhere else in this migration set; a negative score needs its
        // own explicit check. 0 is a valid score, so this is >=, not >.
        DB::statement('alter table marks add constraint marks_score_not_negative check (score is null or score >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('marks');
    }
};
