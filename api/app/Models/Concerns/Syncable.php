<?php

namespace App\Models\Concerns;

use App\Exceptions\StaleVersionException;
use App\Support\CurrentInstitution;
use App\Support\SyncLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/* The three properties docs/spec/data-model.md's Conventions section bundles
   under "a table is synchronisable": a UUID primary key (HasUuidv7), an
   integer version incremented server-side, and a soft delete, per ADR 0001.
   Bundled as one trait, not three separately listed on every model, because
   the version bump on delete (below) has to happen in the same statement as
   SoftDeletes' own deleted_at update: overriding runSoftDelete() here and
   composing SoftDeletes separately on each model would collide (PHP fatal
   errors on two traits defining the same method), so SoftDeletes is used
   internally instead and this trait's own runSoftDelete() is the one that
   wins. Institution and unlock_notes are the two non-synchronisable tables
   (data-model.md, Conventions) and use bare HasUuidv7 instead of this. */
trait Syncable
{
    use HasUuidv7, SoftDeletes;

    protected static function bootSyncable(): void
    {
        static::creating(function (Model $model) {
            // Mirrors the column's own database default of 0; set explicitly
            // so the in-memory attribute matches the row from the moment
            // create() returns, rather than staying null until a refresh().
            $model->version = 0;
        });

        static::saving(function (Model $model) {
            if ($model->exists && static::hasVersionableChanges($model)) {
                $model->version = ((int) $model->getOriginal('version')) + 1;
            }
        });
    }

    /* Every write to a synchronisable table appends to sync_changes in the
       same transaction, behind the institution's advisory lock (ADR 0010,
       SyncLog). save() covers create, update, and restore (which saves with
       deleted_at cleared); delete() covers the soft delete. The institution
       falls back to the current request's when a new row has not been given
       one yet, since BelongsToInstitution fills it in only inside the
       creating event. With neither, the write goes ahead unwrapped and fails
       on the column's NOT NULL, as it always did. */
    public function save(array $options = [])
    {
        $institutionId = $this->institution_id ?? app(CurrentInstitution::class)->id();

        if ($institutionId === null) {
            return parent::save($options);
        }

        return SyncLog::transaction($institutionId, fn () => parent::save($options));
    }

    public function delete()
    {
        if ($this->institution_id === null) {
            return parent::delete();
        }

        return SyncLog::transaction($this->institution_id, fn () => parent::delete());
    }

    protected function performInsert(Builder $query)
    {
        if (! parent::performInsert($query)) {
            return false;
        }

        SyncLog::append($this, array_keys($this->getAttributes()));

        return true;
    }

    /* Excludes a bare touch() (only updated_at dirty) and a genuine no-op
       save() from bumping version: "incremented on every save" means every
       save that actually changes the row, not every call to save(). */
    protected static function hasVersionableChanges(Model $model): bool
    {
        $ignoredColumns = array_filter([
            $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null,
        ]);

        return array_diff(array_keys($model->getDirty()), $ignoredColumns) !== [];
    }

    /* Overrides Eloquent's default update, which qualifies only by primary
       key, to also require the row's version to still match what this
       instance last read. Two processes loading the same row both compute
       original+1 in the `saving` hook above; without this guard, whichever
       UPDATE runs second would silently overwrite the first with no trace
       it ever happened, and the stale-version check the sync protocol
       relies on (ADR 0001) would have nothing left to detect. A zero-row
       update means someone else won the race, surfaced here as an
       exception rather than a lost update. */
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $affected = $this->setKeysForSaveQuery($query)
                ->where('version', (int) $this->getOriginal('version'))
                ->update($dirty);

            if ($affected === 0) {
                throw new StaleVersionException($this);
            }

            SyncLog::append($this, array_keys($dirty));

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /* Overrides SoftDeletes' own runSoftDelete() so the version bump and
       the deleted_at write are the same guarded UPDATE, not two separate
       statements: a delete never calls save(), so performUpdate() above
       can't cover it, and issuing the version bump as its own statement
       first (an earlier version of this trait did exactly that) leaves a
       window where a crash between the two statements strands the row live
       with an already-advanced version, silently defeating the next
       stale-version check. One statement removes the window entirely. */
    protected function runSoftDelete(): void
    {
        $originalVersion = (int) $this->getOriginal('version');
        $newVersion = $originalVersion + 1;

        $time = $this->freshTimestamp();

        $columns = [
            $this->getDeletedAtColumn() => $this->fromDateTime($time),
            'version' => $newVersion,
        ];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $affected = $this->setKeysForSaveQuery($this->newModelQuery())
            ->where('version', $originalVersion)
            ->update($columns);

        if ($affected === 0) {
            throw new StaleVersionException($this);
        }

        $this->version = $newVersion;
        $this->syncOriginalAttributes(array_keys($columns));

        SyncLog::append($this, [$this->getDeletedAtColumn()]);

        $this->fireModelEvent('trashed', false);
    }
}
