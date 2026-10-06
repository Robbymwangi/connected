<?php

namespace App\Sync\Push;

use App\Models\SyncMutation;
use App\Models\User;
use App\Support\CurrentInstitution;
use App\Support\SyncLog;
use App\Sync\SyncPull;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/* POST /sync, one entry at a time (docs/spec/sync-protocol.md). This class owns
   the order of checks; a PushHandler owns a table's own rules; neither the
   controller nor a handler opens a transaction or saves.

   The order, per the amended spec: the envelope (no database); rule 1, replay;
   the record, found scoped to the institution and soft-deleted rows included, and
   locked; authorization; the table's own checks; then the version rules (a base
   ahead of the server is invalid; behind is a conflict; equal applies; base 0 on
   an unknown id creates); and last the validation of the row that would result.
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
        'marks' => MarkPushHandler::class,
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

            // Rule 4 (merge or conflict, from the change log) is 3.2b; until then any stale base conflicts.
            if ($entry->baseVersion < $record->version) {
                $outcome = PushOutcome::conflict($entry->id, SyncPull::row($record));
                $this->record($entry, $outcome, $record->version);

                return $outcome;
            }
        }

        $model = $handler->resulting($entry, $record);

        $creating = $record === null;
        $model->save();
        $creating = false;

        $outcome = PushOutcome::accepted($entry->id, $model->version);
        $this->record($entry, $outcome, $model->version);

        return $outcome;
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

    private function record(PushEntry $entry, PushOutcome $outcome, ?int $version): void
    {
        SyncMutation::create([
            'id' => $entry->id,
            'institution_id' => $this->user->institution_id,
            'user_id' => $this->user->id,
            'table' => $entry->table,
            'record_id' => $entry->recordId,
            'status' => $outcome->status,
            'version' => $version,
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

            return $outcome;
        });
    }
}
