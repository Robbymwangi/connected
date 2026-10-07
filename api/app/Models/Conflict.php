<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* side_a, side_b, proposals, referral, and resolution are stored as JSON
   columns rather than normalised into child tables, matching the shapes ADR
   0002 and fixtures/conflicts.ts already define: a conflict is read and
   written as one document, never queried by the contents of one proposal, so
   each casts to a plain PHP array rather than a dedicated value object.
   docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'mark_id', 'base_version', 'mark_version', 'side_a', 'side_b', 'proposals', 'referral', 'resolution', 'resolved_at'])]
class Conflict extends Model
{
    use BelongsToInstitution, Syncable;

    /* The mark's version when this was raised: the server's own bookkeeping for resolution commands, never
       sent to a device (SyncLog leaves hidden attributes out of the log, and so out of every pull). */
    protected $hidden = ['mark_version'];

    protected function casts(): array
    {
        return [
            'side_a' => 'array',
            'side_b' => 'array',
            'proposals' => 'array',
            'referral' => 'array',
            'resolution' => 'array',
            'mark_version' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function mark(): BelongsTo
    {
        return $this->belongsTo(Mark::class);
    }
}
