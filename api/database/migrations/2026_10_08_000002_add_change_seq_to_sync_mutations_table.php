<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* The sync_changes row a mutation wrote: how a conflict finds the write that
       produced a record's current version (side A), exactly, by link, never by guessing
       from a (table, record, version) match. A no-op, a patch that changes nothing, and a
       conflict all store the version the record already had, so a lookup by version would
       credit the producer to a mutation that never wrote it. Null when the entry wrote
       nothing; unique, because a log row has one writer; allowed only on accepted or merged.
       Adding a column fires no row trigger, so the append-only guarantee is untouched. */
    public function up(): void
    {
        Schema::table('sync_mutations', function (Blueprint $table) {
            $table->unsignedBigInteger('change_seq')->nullable()->unique();
            $table->foreign('change_seq')->references('seq')->on('sync_changes');
        });

        DB::statement("alter table sync_mutations add constraint sync_mutations_change_seq_only_when_applied check (change_seq is null or status in ('accepted', 'merged'))");
    }

    public function down(): void
    {
        Schema::table('sync_mutations', function (Blueprint $table) {
            $table->dropForeign(['change_seq']);
            $table->dropColumn('change_seq');
        });
    }
};
