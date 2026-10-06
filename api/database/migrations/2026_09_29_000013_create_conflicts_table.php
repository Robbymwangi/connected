<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* side_a, side_b, proposals, referral, and resolution are stored as JSON
       columns rather than normalised into child tables, matching the shapes
       ADR 0002 and fixtures/conflicts.ts already define: a conflict is read
       and written as one document, never queried by the contents of one
       proposal. docs/spec/data-model.md (#34). */
    public function up(): void
    {
        Schema::create('conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('mark_id')->constrained('marks');
            $table->unsignedInteger('base_version');
            $table->jsonb('side_a');
            $table->jsonb('side_b');
            $table->jsonb('proposals')->default(DB::raw("'[]'"));
            $table->jsonb('referral')->nullable();
            $table->jsonb('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        // base_version's unsignedInteger is silently plain on Postgres, same
        // as elsewhere in this set; 0 is a valid base version (ADR 0001 rule
        // 2), so this is >=, not >.
        DB::statement('alter table conflicts add constraint conflicts_base_version_not_negative check (base_version >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('conflicts');
    }
};
