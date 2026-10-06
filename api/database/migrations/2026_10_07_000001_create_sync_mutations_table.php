<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* The record of every decided push entry, backing rule 1 of POST /sync
       (docs/spec/sync-protocol.md; docs/spec/data-model.md, sync_mutations).
       Append-only: no version, no soft delete, no updated_at, and received_at
       is the server's stamp, not a record timestamp. Not synchronisable.

       id is the client's mutation id, supplied and never generated, so it is a
       client UUID like every key. record_id has no foreign key because it
       points into whichever table `table` names. The replay lookup reads this
       table across institutions on purpose (a known id from another school must
       be answered invalid, not treated as new), so the model has one named,
       commented unscoped read; every other read is institution-scoped.

       version is the record's version in the outcome. A rejection has none, and
       a conflict stores the current version at decision time (not sent back).
       The check constraints keep a stored outcome coherent, so later code does
       not have to re-check it: a rejection has no version, an invalid row has a
       reason and nothing else does, and a conflict id belongs only to a conflict.
       received_at defaults to clock_timestamp(), the moment of the insert, as on
       sync_changes. The (table, record_id, version) index finds the mutation
       that produced a given version of a record, for a conflict side's editId. */
    public function up(): void
    {
        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('table', 64);
            $table->uuid('record_id');
            $table->enum('status', ['accepted', 'merged', 'conflict', 'invalid', 'forbidden']);
            $table->unsignedInteger('version')->nullable();
            $table->foreignUuid('conflict_id')->nullable()->constrained('conflicts');
            $table->char('payload_hash', 64);
            $table->text('reason')->nullable();
            $table->string('at', 64)->nullable();
            $table->timestampTz('received_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->index(['table', 'record_id', 'version']);
        });

        DB::statement("alter table sync_mutations add constraint sync_mutations_version_matches_status check ((version is null) = (status in ('invalid', 'forbidden')))");
        DB::statement("alter table sync_mutations add constraint sync_mutations_reason_matches_status check ((reason is not null) = (status = 'invalid'))");
        DB::statement("alter table sync_mutations add constraint sync_mutations_conflict_id_only_on_conflict check (conflict_id is null or status = 'conflict')");

        // Append-only is enforced here, not only in the model: a bulk query
        // (SyncMutation::query()->update(), a raw delete) fires no model events,
        // and a changed or vanished outcome would break replay.
        // `create or replace`, because migrate:fresh drops the tables but not their
        // functions: a plain `create function` would fail on every rebuild after the first.
        DB::unprepared(<<<'SQL'
            create or replace function sync_mutations_append_only() returns trigger language plpgsql as $$
            begin
                raise exception 'sync_mutations is append-only';
            end;
            $$
            SQL);
        DB::statement('create trigger sync_mutations_append_only before update or delete on sync_mutations for each row execute function sync_mutations_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_mutations');
        DB::statement('drop function if exists sync_mutations_append_only()');
    }
};
