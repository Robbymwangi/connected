<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* The mark's version when the conflict was raised, which is the version side A produced
       (docs/spec/data-model.md, conflicts). A resolution command measures "has the mark moved since" against
       it. It is recorded on the conflict because a follow-up conflict, raised by a resolution command, has no
       mutation of its own to read it from: the command that raised it is `accepted`, and a conflict link is
       allowed only on a `conflict` outcome. Server-only: the model hides it, so it never reaches the change
       log or a device.

       Additive, with a backfill for conflicts that already exist. The producer's mutation row holds the
       version; where side A has no mutation, base_version + 1 is used, never above the truth, because side A's
       write is always above side B's stale base. A value at or below the truth can only over-report that the
       mark moved (a follow-up conflict where none was needed), never miss a move and overwrite it. */
    public function up(): void
    {
        if (! Schema::hasColumn('conflicts', 'mark_version')) {
            Schema::table('conflicts', function (Blueprint $table) {
                $table->unsignedInteger('mark_version')->nullable();
            });
        }

        DB::statement(<<<'SQL'
            update conflicts c set mark_version = sm.version
            from sync_mutations sm
            where c.mark_version is null
              and sm.id::text = c.side_a->>'editId'
              and sm."table" = 'marks'
              and sm.record_id = c.mark_id
              and sm.change_seq is not null
              and sm.version is not null
            SQL);

        DB::statement(<<<'SQL'
            update conflicts c set mark_version = least(c.base_version + 1, m.version)
            from marks m
            where c.mark_version is null and m.id = c.mark_id
            SQL);

        DB::statement('alter table conflicts alter column mark_version set not null');
        DB::statement('alter table conflicts drop constraint if exists conflicts_mark_version_positive');
        DB::statement('alter table conflicts add constraint conflicts_mark_version_positive check (mark_version >= 1)');
    }

    public function down(): void
    {
        DB::statement('alter table conflicts drop constraint if exists conflicts_mark_version_positive');

        if (Schema::hasColumn('conflicts', 'mark_version')) {
            Schema::table('conflicts', function (Blueprint $table) {
                $table->dropColumn('mark_version');
            });
        }
    }
};
