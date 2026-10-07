<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* Rule 4 of POST /sync reads one record's history: the sync_changes rows for a
       (table, record_id) above a base version, in version order
       (docs/spec/sync-protocol.md; docs/spec/data-model.md, sync_changes). Without this
       index each stale-write check scans the log, which only grows. institution_id leads
       because every read is institution-scoped. Plain, not unique: the history check
       compares the exact versions it finds, so uniqueness is not what keeps it correct. */
    public function up(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->index(['institution_id', 'table', 'record_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropIndex(['institution_id', 'table', 'record_id', 'version']);
        });
    }
};
