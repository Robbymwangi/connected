<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/* One row per write to a synchronisable table: the log GET /sync reads, whose
   seq is the pull cursor (ADR 0010). Append-only and not itself
   synchronisable: no version, no SoftDeletes, no timestamps pair, and it
   never appears on either sync verb as a table. The key is the database's
   bigserial seq, not a client UUID, since only the server writes it.
   docs/spec/data-model.md, sync_changes. Slice 2 appends rows from Syncable;
   nothing here is meant to be updated after it is written. received_at is
   deliberately not fillable: it is the server's audit stamp, filled by the
   column default, never chosen by a caller. */
#[Fillable(['institution_id', 'table', 'record_id', 'version', 'fields'])]
class SyncChange extends Model
{
    use BelongsToInstitution;

    protected $primaryKey = 'seq';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
