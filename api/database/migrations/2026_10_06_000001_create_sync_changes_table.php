<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* The change log that backs GET /sync (docs/spec/data-model.md,
       sync_changes; ADR 0010). Append-only: no version, no soft delete, no
       updated_at, and received_at instead of created_at since it is a server
       audit stamp, not a record timestamp.

       seq is a bigserial primary key and the pull cursor itself. It is the
       one deliberate exception to client-generated UUID keys: the rule exists
       because records are created offline, and only the server ever writes a
       log row. record_id has no foreign key because it points into whichever
       table `table` names.

       received_at defaults to clock_timestamp(), the wall clock at the moment
       of the insert, not now(), which Postgres freezes at the start of the
       transaction. The audit amendment wants the moment the write happened.
       It is never read by pull; the cursor is seq alone. */
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->foreignUuid('institution_id')->constrained('institutions');
            $table->string('table');
            $table->uuid('record_id');
            $table->unsignedInteger('version');
            $table->jsonb('fields');
            $table->timestampTz('received_at', 6)->default(DB::raw('clock_timestamp()'));

            $table->index(['institution_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
