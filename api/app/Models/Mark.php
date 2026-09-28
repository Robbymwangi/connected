<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ramsey\Uuid\Uuid;

/* id is a deterministic UUIDv5 of (assessment_id, student_id, criterion_id),
   computed identically on the device and the server, not a fresh UUID per
   row: two devices creating the same cell offline collide as an ordinary
   stale-version conflict rather than producing two rows.
   docs/spec/data-model.md (#34), Grains and identity.

   MARK_UUID_NAMESPACE is that fixed namespace: generated once, hardcoded
   here, and never changed; it lives alongside this model rather than in the
   migration itself because the migration's anonymous class has no stable
   name a device-side or future server-side caller could reference. The
   comment in 2026_09_29_000012_create_marks_table.php points back here. */
#[Fillable(['id', 'institution_id', 'assessment_id', 'student_id', 'criterion_id', 'mark_kind', 'score', 'last_edited_by'])]
class Mark extends Model
{
    use Syncable;

    public const MARK_UUID_NAMESPACE = '733181fb-9c96-4898-a85d-69d3d13d83d3';

    /* Overrides HasUuidv7's random UUIDv7 with the deterministic UUIDv5, but
       only when nothing supplied an id already: HasUuids' creating hook
       calls this exactly when the attribute is still empty, so a
       client-supplied mark id (the ordinary offline-create case) is kept as
       given, unchecked here; validating that it actually matches this
       formula is a write-policy concern for a later ticket, not this one. */
    public function newUniqueId(): string
    {
        $name = "{$this->assessment_id}:{$this->student_id}:{$this->criterion_id}";

        return Uuid::uuid5(self::MARK_UUID_NAMESPACE, $name)->toString();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class);
    }

    public function lastEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(Conflict::class);
    }
}
