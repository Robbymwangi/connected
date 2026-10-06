<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Scopes\InstitutionScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/* The record of one decided POST /sync entry (docs/spec/sync-protocol.md,
   rule 1; docs/spec/data-model.md, sync_mutations). Append-only and not
   synchronisable: no version, no SoftDeletes, no timestamps pair, and it never
   appears on either sync verb. The key is the client's mutation id, supplied
   with every row and never generated, so there is no HasUuids. received_at is
   deliberately not fillable: it is the server's audit stamp, filled by the
   column default, never chosen by a caller. */
#[Fillable(['id', 'institution_id', 'user_id', 'table', 'record_id', 'status', 'version', 'conflict_id', 'payload_hash', 'reason', 'at'])]
class SyncMutation extends Model
{
    use BelongsToInstitution;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('sync_mutations is append-only.'));
        static::deleting(fn () => throw new LogicException('sync_mutations is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /* The one read that is not institution-scoped, on purpose. A mutation id is
       a global primary key, so a known id from another institution (or another
       user) must be found in order to be answered invalid; scoped, it would look
       unknown, the entry would be processed, and its insert would collide on the
       key. The caller checks that the institution and user match before any
       stored content is returned. Every other read of this model is scoped. */
    public static function findForReplay(string $id): ?self
    {
        return static::query()->withoutGlobalScope(InstitutionScope::class)->find($id);
    }
}
