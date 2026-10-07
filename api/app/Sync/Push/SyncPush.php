<?php

namespace App\Sync\Push;

use App\Models\Conflict;
use App\Models\SyncChange;
use App\Models\SyncMutation;
use App\Models\User;
use App\Support\CurrentInstitution;
use App\Support\SyncLog;
use App\Sync\ServerNotifications;
use App\Sync\SyncPull;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/* POST /sync, one entry at a time (docs/spec/sync-protocol.md). This class owns
   the order of checks; a PushHandler owns a table's own rules; neither the
   controller nor a handler opens a transaction or saves.

   The order, per the amended spec: the envelope (no database); rule 1, replay;
   the record, found scoped to the institution and soft-deleted rows included, and
   locked; authorization; the table's own checks; then the version rules (a base
   ahead of the server is invalid; behind is rule 4, decided by StaleBase from what moved
   on the record since; equal applies; base 0 on an unknown id creates); and last the
   validation of the row that would result.
   An unauthorized stale edit is therefore never answered `conflict`, which would
   leave it retained in the outbox forever.

   Each entry is its own SyncLog::transaction, with the institution's advisory lock
   taken first, so every read an entry depends on happens inside it after the
   lock. A rejection is thrown, never caught inside that transaction: it rolls
   back, the catch below runs with nothing open, and reject() then records the
   rejection in a fresh transaction, which survives. Catching inside would leave a
   database error's aborted state behind, and the next statement would fail.
   Nothing here may therefore be called from inside another SyncLog transaction.

   An unexpected error is not a rule outcome: it propagates, the batch stops with a
   5xx, earlier entries stay committed and recorded, and the failing entry is not
   recorded, so a resend replays the first and decides the rest afresh. */
final class SyncPush
{
    /** @var array<string, class-string<PushHandler>> */
    private const HANDLERS = [
        'assessments' => AssessmentPushHandler::class,
        'conflicts' => ConflictPushHandler::class,
        'marks' => MarkPushHandler::class,
        'notifications' => NotificationPushHandler::class,
    ];

    /** Wire names the server alone owns; a device sending one is told, not silently ignored. */
    private const SERVER_OWNED = ['version', 'institutionId', 'lastEditedBy', 'createdBy', 'receivedAt', 'createdAt', 'updatedAt'];

    public function __construct(private readonly User $user)
    {
        // Every scoped lookup below depends on the request's institution, and outside
        // a request the scope filters nothing, which would make another school's rows visible.
        if (app(CurrentInstitution::class)->id() !== $user->institution_id) {
            throw new LogicException('SyncPush must run inside a request resolved to the user\'s institution.');
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public function process(array $raw): PushOutcome
    {
        $entry = PushEntry::from($raw);

        try {
            $handler = $this->envelope($entry);
        } catch (SyncRejection $rejection) {
            return $this->reject($entry, $rejection);
        }

        $creating = false;

        try {
            // By reference on purpose: an arrow function would capture $creating by value, and the
            // catch below would never see that the failing statement was the create.
            return SyncLog::transaction($this->user->institution_id, function () use ($entry, $handler, &$creating) {
                return $this->decide($entry, $handler, $creating);
            });
        } catch (SyncRejection $rejection) {
            return $this->reject($entry, $rejection);
        } catch (ValidationException $exception) {
            return $this->reject($entry, SyncRejection::invalid((string) collect($exception->errors())->flatten()->first()));
        } catch (AuthorizationException) {
            return $this->reject($entry, SyncRejection::forbidden());
        } catch (UniqueConstraintViolationException $exception) {
            // Primary keys are global: a create whose id exists in another institution collides
            // here, and that institution's row is invisible to us. Anything else is a defect.
            if ($creating) {
                return $this->reject($entry, SyncRejection::forbidden());
            }

            throw $exception;
        }
    }

    private function envelope(PushEntry $entry): PushHandler
    {
        if (! $entry->isRecordable()) {
            throw SyncRejection::invalid('id and recordId must be UUIDs and table a lowercase identifier of at most 64 characters');
        }

        $class = self::HANDLERS[$entry->table] ?? null;

        if ($class === null) {
            throw SyncRejection::invalid(
                array_key_exists($entry->table, SyncPull::modelsByTable())
                    ? "table is pull-only: {$entry->table}"
                    : "unknown table: {$entry->table}",
            );
        }

        if (! is_int($entry->baseVersion) || $entry->baseVersion < 0) {
            throw SyncRejection::invalid('baseVersion must be a non-negative integer');
        }

        if (! is_array($entry->fields) || $entry->fields === [] || array_is_list($entry->fields)) {
            throw SyncRejection::invalid('fields must be a non-empty object');
        }

        $handler = new $class($this->user);
        $columns = $handler->columns();
        $refused = $handler->refused();

        foreach (array_keys($entry->fields) as $name) {
            $name = (string) $name;

            if (in_array($name, self::SERVER_OWNED, true)) {
                throw SyncRejection::invalid("server-owned field: {$name}");
            }

            if (isset($refused[$name])) {
                throw SyncRejection::invalid($refused[$name]);
            }

            if (! isset($columns[$name])) {
                throw SyncRejection::invalid("unknown field: {$name}");
            }
        }

        return $handler;
    }

    private function decide(PushEntry $entry, PushHandler $handler, bool &$creating): PushOutcome
    {
        $known = SyncMutation::findForReplay($entry->id);

        if ($known !== null) {
            return $this->replayOf($known, $entry);
        }

        $record = $handler->lockRecord($entry);

        // No record here to be stale against: forbidden, consistent with a cross-institution row being simply not found.
        if ($record === null && $entry->baseVersion > 0) {
            throw SyncRejection::forbidden();
        }

        if (! $handler->authorize($record)) {
            throw SyncRejection::forbidden();
        }

        $handler->check($entry, $record);

        if ($record !== null) {
            if ($entry->baseVersion > $record->version) {
                throw SyncRejection::invalid('baseVersion is ahead of the server');
            }

            if ($entry->baseVersion < $record->version) {
                // A command is never merged: the device gets the conflict as it stands, and decides again.
                return $handler->isCommand()
                    ? $this->conflict($entry, $record, SyncPull::row($record))
                    : $this->stale($entry, $handler, $record);
            }
        }

        $model = $handler->resulting($entry, $record);

        $before = $record?->version ?? 0;
        $creating = $record === null;
        $model->save();
        $creating = false;
        $handler->written($entry, $model);

        $outcome = PushOutcome::accepted($entry->id, $model->version);
        $this->record($entry, $outcome, $model->version, $this->changeSeqOf($model, $before));

        return $outcome;
    }

    /* Rule 4: an entry whose base is behind the record's. What it touches is compared with
       what moved on the record since, from the change log (StaleBase decides; this class
       only gathers its inputs and acts on the verdict).

       Order matters. The record is snapshotted first, because resulting() fills the locked
       record in place and a conflict must show the row as it stood. The entry's own values
       are validated before any branch, so a stale entry holding an invalid value is invalid,
       never stored as a conflict side. resulting() runs only on a merge. */
    private function stale(PushEntry $entry, PushHandler $handler, Model $record): PushOutcome
    {
        $current = SyncPull::row($record);
        $history = RecordHistory::above($record, $entry->baseVersion);
        $sent = $handler->incoming($entry, $record);

        $currentValues = [];

        foreach (array_keys($sent) as $wire) {
            $currentValues[$wire] = $handler->currentValue($record, $wire);
        }

        $verdict = StaleBase::decide(
            sent: $sent,
            base: $entry->baseVersion,
            current: $record->version,
            history: $history,
            columns: $handler->columns(),
            groups: $handler->mergeGroups(),
            identity: $handler->identity(),
            currentValues: $currentValues,
            trashed: $record->trashed(),
            entryDeletes: array_key_exists('deletedAt', $entry->fields) && $entry->fields['deletedAt'] !== null,
        );

        return match ($verdict) {
            StaleVerdict::Merged => $this->merge($entry, $handler, $record),
            StaleVerdict::Unchanged => $this->unchanged($entry, $record),
            StaleVerdict::UnchangedAfterCollision => $this->collided($entry, $handler, $record, $history, $sent),
            StaleVerdict::ConflictOverlap, StaleVerdict::ConflictIncomplete => $this->conflict($entry, $record, $current, $this->openConflict($handler, $entry, $record, $history, $sent)),
            // Nothing live remains to choose between, and no pushable table can be deleted from a device, so no record.
            StaleVerdict::ConflictDeleted => $this->conflict($entry, $record, $current),
            StaleVerdict::TrashedBase => throw SyncRejection::invalid('the record has been deleted'),
        };
    }

    /* Disjoint fields: applied through the model like any write, so the version bumps, the
       log row is appended, and the version-guarded UPDATE runs. A merge always has at least
       one differing field, so it always writes a log row. */
    private function merge(PushEntry $entry, PushHandler $handler, Model $record): PushOutcome
    {
        $before = $record->version;
        $model = $handler->resulting($entry, $record);
        $model->save();

        $outcome = PushOutcome::merged($entry->id, $model->version);
        $this->record($entry, $outcome, $model->version, $this->changeSeqOf($model, $before));

        return $outcome;
    }

    /* Everything it touches already holds the value it sent: nothing to write. No version, no
       log row, last_edited_by unchanged; the outcome is still recorded, so a resend replays. */
    private function unchanged(PushEntry $entry, Model $record): PushOutcome
    {
        $outcome = PushOutcome::accepted($entry->id, $record->version);
        $this->record($entry, $outcome, $record->version);

        return $outcome;
    }

    /* Two edits that agree. The cell is untouched and the outcome is the plain no-op, because
       the device's value is the one that stands; for a mark the collision is also written down as
       an already-resolved conflict, so the second teacher can see it happened and who was first.
       The conflict is written before the mutation row, which does not reference it. */
    private function collided(PushEntry $entry, PushHandler $handler, Model $record, RecordHistory $history, array $sent): PushOutcome
    {
        if ($handler instanceof MarkPushHandler) {
            $this->raiseFor($entry, $record, $history, $sent, auto: true);
        }

        return $this->unchanged($entry, $record);
    }

    /**
     * @param  array<string, mixed>  $current  the record as it stood before anything filled it
     */
    private function conflict(PushEntry $entry, Model $record, array $current, ?Conflict $conflict = null): PushOutcome
    {
        $outcome = PushOutcome::conflict($entry->id, $current, $conflict?->id);
        $this->record($entry, $outcome, $record->version);

        return $outcome;
    }

    /* A conflict record is a mark conflict (ADR 0002 amendment): any other table's conflict is
       only the response, with the current row and no record. Written before the mutation row,
       which references it. */
    private function openConflict(PushHandler $handler, PushEntry $entry, Model $record, RecordHistory $history, array $sent): ?Conflict
    {
        return $handler instanceof MarkPushHandler
            ? $this->raiseFor($entry, $record, $history, $sent, auto: false)
            : null;
    }

    /**
     * @param  array<string, mixed>  $sent  the entry's own validated cell
     */
    private function raiseFor(PushEntry $entry, Model $record, RecordHistory $history, array $sent, bool $auto): Conflict
    {
        $conflicts = new MarkConflicts($this->user);

        return $conflicts->raise($record, $history, $entry->baseVersion, $conflicts->side($entry->id, $this->user->id, $this->user->name, $sent, $entry->at), $auto);
    }

    /* The sync_changes row this entry just wrote, or null when it wrote none: a patch that
       changes nothing leaves the version where it was and appends nothing. Read under the
       lock, so the row at this version is the one this save produced. */
    private function changeSeqOf(Model $model, int $versionBefore): ?int
    {
        if ($model->version === $versionBefore) {
            return null;
        }

        $seq = SyncChange::query()
            ->where('table', $model->getTable())
            ->where('record_id', $model->getKey())
            ->where('version', $model->version)
            ->value('seq');

        return $seq === null ? null : (int) $seq;
    }

    /* A known mutation id: the stored outcome, read back. The lookup is not scoped by
       institution or user (findForReplay), so a match belonging to someone else is
       answered invalid without a word of what was stored, and a match with a
       different payload is answered invalid, never replayed, because replaying
       would silently drop whatever the device had added. */
    private function replayOf(SyncMutation $known, PushEntry $entry): PushOutcome
    {
        if ($known->institution_id !== $this->user->institution_id || $known->user_id !== $this->user->id) {
            return PushOutcome::rejected($entry->id, SyncRejection::invalid('mutation id was already used'));
        }

        if (! hash_equals($known->payload_hash, $entry->payloadHash)) {
            return PushOutcome::rejected($entry->id, SyncRejection::invalid('mutation id was already used with a different payload'));
        }

        $current = null;

        if ($known->status === 'conflict') {
            $class = self::HANDLERS[$known->table] ?? null;
            $record = $class === null ? null : (new $class($this->user))->find($known->record_id);
            $current = $record === null ? null : SyncPull::row($record);
        }

        return PushOutcome::fromStored($known, $current);
    }

    private function record(PushEntry $entry, PushOutcome $outcome, ?int $version, ?int $changeSeq = null): void
    {
        SyncMutation::create([
            'id' => $entry->id,
            'institution_id' => $this->user->institution_id,
            'user_id' => $this->user->id,
            'table' => $entry->table,
            'record_id' => $entry->recordId,
            'status' => $outcome->status,
            'version' => $version,
            'conflict_id' => $outcome->conflictId,
            'change_seq' => $changeSeq,
            'payload_hash' => $entry->payloadHash,
            'reason' => $outcome->reason,
            'at' => $entry->at,
        ]);
    }

    /* Record a rejection in its own transaction, after the entry's has rolled back. It
       looks the id up again under the lock, for two cases: a concurrent identical
       resend that committed meanwhile, and a resend of an envelope-invalid entry,
       which reaches here without ever passing rule 1 and would otherwise collide
       with its own earlier record on the key. An entry that cannot be keyed is
       answered and forgotten. */
    private function reject(PushEntry $entry, SyncRejection $rejection): PushOutcome
    {
        if (! $entry->isRecordable()) {
            return PushOutcome::rejected($entry->id, $rejection);
        }

        return SyncLog::transaction($this->user->institution_id, function () use ($entry, $rejection) {
            $known = SyncMutation::findForReplay($entry->id);

            if ($known !== null) {
                return $this->replayOf($known, $entry);
            }

            $outcome = PushOutcome::rejected($entry->id, $rejection);
            $this->record($entry, $outcome, null);

            // Only here, on the first decision of this entry: a resend is caught by the replay lookup above
            // and writes nothing again. Written in this fresh transaction because the entry's own rolled back.
            if ($rejection->blockedAssessmentId !== null) {
                (new ServerNotifications)->editBlocked($this->user, $rejection->blockedAssessmentId);
            }

            return $outcome;
        });
    }
}
