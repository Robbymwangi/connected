<?php

namespace App\Sync\Push;

use App\Models\Assessment;
use App\Models\Conflict;
use App\Models\Criterion;
use App\Models\Mark;
use App\Models\SubjectModeration;
use App\Sync\ServerClock;
use App\Sync\ServerNotifications;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/* Conflict commands over POST /sync (docs/spec/sync-protocol.md, "Conflict commands"). An entry for the `conflicts`
   table is a command, not a field patch: it names what it does, is checked against the conflict's stored state, and is
   never merged by rule 4 (SyncPush answers a base behind the conflict with the conflict itself). This handler does the
   lookups, the shape validation, and the build of the new stored value; ConflictPolicy, which has no database, decides
   who may do what.

   What a device sends is ids and a choice. Everything stored is rebuilt from validated pieces: names come from the
   server, the clock is the server's, the referral's reason is computed from the stored proposals. A conflict is never
   created from a device (they are raised by the server), so every entry here has a record to act on.

   Locks are taken in the order finalize uses: the assessment, then the mark, then the conflict. The two ids read before
   locking (mark_id, assessment_id) never change, so reading them unlocked is safe. Anything a device could point at that
   the institution scope hides is answered invalid, not dereferenced: a foreign key does not check the institution. */
final class ConflictPushHandler extends PushHandler
{
    private const NOTE_LIMIT = 2000;

    private ?Mark $mark = null;

    private ?Assessment $assessment = null;

    private ConflictRole $role = ConflictRole::None;

    private CommandKind $kind = CommandKind::Propose;

    /** @var array<string, mixed> the validated choice, rebuilt */
    private array $choice = [];

    private string $note = '';

    public function columns(): array
    {
        return ['proposal' => 'proposals', 'referral' => 'referral', 'resolution' => 'resolution'];
    }

    public function refused(): array
    {
        return [
            'proposals' => 'a device sends one proposal, never the proposals array',
            'resolvedAt' => 'resolvedAt is the server\'s clock',
            'resolution' => 'resolving a conflict is not accepted yet',
        ];
    }

    public function isCommand(): bool
    {
        return true;
    }

    public function incoming(PushEntry $entry, Model $record): array
    {
        throw new LogicException('A command is never decided by rule 4.');
    }

    public function find(string $recordId, bool $lock = false): ?Model
    {
        $query = Conflict::withTrashed();

        return ($lock ? $query->lockForUpdate() : $query)->find($recordId);
    }

    public function lockRecord(PushEntry $entry): ?Model
    {
        $conflict = $this->find($entry->recordId);

        if ($conflict === null) {
            return null;
        }

        $mark = Mark::withTrashed()->find($conflict->mark_id);

        if ($mark !== null) {
            $this->assessment = Assessment::withTrashed()->lockForUpdate()->find($mark->assessment_id);
            $this->mark = Mark::withTrashed()->lockForUpdate()->find($mark->id);
        }

        return $this->find($entry->recordId, true);
    }

    /* Identity only: a party, or a moderator of the assessment's subject. What either may do is the policy's. */
    public function authorize(?Model $record): bool
    {
        if ($record === null) {
            return true;
        }

        $this->role = ConflictPolicy::role(
            (string) $this->user->id,
            (string) ($record->side_a['userId'] ?? ''),
            (string) ($record->side_b['userId'] ?? ''),
            $this->moderatesSubject(),
        );

        return $this->role !== ConflictRole::None;
    }

    public function check(PushEntry $entry, ?Model $record): void
    {
        if ($record === null) {
            throw SyncRejection::invalid('conflicts are raised by the server');
        }

        if ($this->mark === null || $this->assessment === null) {
            throw SyncRejection::invalid('the conflict\'s mark does not resolve');
        }

        if (count($entry->fields) !== 1) {
            throw SyncRejection::invalid('a conflict command is sent on its own: one of proposal or referral');
        }

        $name = (string) array_key_first($entry->fields);
        $this->kind = match ($name) {
            'proposal' => CommandKind::Propose,
            'referral' => CommandKind::Refer,
            default => throw SyncRejection::invalid('resolving a conflict is not accepted yet'),
        };

        $rejection = ConflictPolicy::actor($this->role, $this->kind);

        if ($rejection !== null) {
            throw $rejection;
        }

        $this->assertNoReceivedAt($entry->fields);

        $value = $entry->fields[$name];

        match ($this->kind) {
            CommandKind::Propose => $this->validateProposal($value, $record),
            CommandKind::Refer => $this->validateReferral($value),
            default => throw new LogicException('Not reachable before the resolve command exists.'),
        };
    }

    /* Only at an equal version, so the state is the one the device saw. The conflict is returned unsaved. */
    public function resulting(PushEntry $entry, ?Model $record): Model
    {
        if ($record->trashed()) {
            throw SyncRejection::invalid('the conflict has been deleted');
        }

        $rejection = ConflictPolicy::state($this->role, $this->kind, ConflictState::of($record), (string) $this->user->id);

        if ($rejection !== null) {
            throw $rejection;
        }

        $record->fill($this->kind === CommandKind::Propose
            ? ['proposals' => [...($record->proposals ?? []), $this->proposalFor($entry)]]
            : ['referral' => $this->referralFor($entry, $record)]);

        return $record;
    }

    /* A referral tells the subject's moderators who are not parties. Inside the entry's own transaction, so a replay,
       which never reaches here, writes nothing a second time. */
    public function written(PushEntry $entry, Model $saved): void
    {
        if ($this->kind === CommandKind::Refer) {
            (new ServerNotifications)->referred($saved, $this->assessment);
        }
    }

    private function moderatesSubject(): bool
    {
        return $this->assessment !== null
            && SubjectModeration::query()->where('user_id', $this->user->id)->where('subject_id', $this->assessment->subject_id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalFor(PushEntry $entry): array
    {
        return [
            'byId' => (string) $this->user->id,
            'by' => $this->user->name,
            'choice' => $this->choice,
            'note' => $this->note,
            'at' => $entry->at,
            'receivedAt' => ServerClock::now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referralFor(PushEntry $entry, Conflict $conflict): array
    {
        $common = ['at' => $entry->at, 'receivedAt' => ServerClock::now()];

        if (ConflictPolicy::referralReason(count($conflict->proposals ?? [])) === 'rounds') {
            return ['reason' => 'rounds', ...$common];
        }

        return ['reason' => 'party', 'byId' => (string) $this->user->id, 'by' => $this->user->name, ...$common];
    }

    /* The server owns every clock. A device's own time is the entry's `at`, so a time nested anywhere is a mistake. */
    private function assertNoReceivedAt(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $inner) {
            if ($key === 'receivedAt') {
                throw SyncRejection::invalid('receivedAt is the server\'s clock');
            }

            $this->assertNoReceivedAt($inner);
        }
    }

    private function validateProposal(mixed $proposal, Conflict $conflict): void
    {
        $this->assertObject($proposal, ['byId', 'choice', 'note'], 'proposal');
        $this->assertByIsMe($proposal['byId']);

        $this->choice = $this->validatedChoice($proposal['choice'], $conflict);

        $note = $proposal['note'];

        if (! is_string($note) || str_contains($note, "\0") || trim($note) === '' || mb_strlen($note) > self::NOTE_LIMIT) {
            throw SyncRejection::invalid('note must be a non-blank string of at most '.self::NOTE_LIMIT.' characters');
        }

        $this->note = $note;
    }

    private function validateReferral(mixed $referral): void
    {
        $this->assertObject($referral, ['byId'], 'referral');
        $this->assertByIsMe($referral['byId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedChoice(mixed $choice, Conflict $conflict): array
    {
        if (! is_array($choice) || array_is_list($choice) || ! is_string($choice['kind'] ?? null)) {
            throw SyncRejection::invalid('choice must be an object with a kind');
        }

        if ($choice['kind'] === 'side') {
            $this->assertObject($choice, ['kind', 'editId'], 'choice');

            if (! is_string($choice['editId']) || ! in_array($choice['editId'], [$conflict->side_a['editId'] ?? null, $conflict->side_b['editId'] ?? null], true)) {
                throw SyncRejection::invalid('editId must name one of the conflict\'s sides');
            }

            return ['kind' => 'side', 'editId' => $choice['editId']];
        }

        if ($choice['kind'] === 'corrected') {
            $this->assertObject($choice, ['kind', 'mark'], 'choice');

            return ['kind' => 'corrected', 'mark' => $this->validatedCorrectedMark($choice['mark'])];
        }

        throw SyncRejection::invalid('choice kind must be side or corrected');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCorrectedMark(mixed $mark): array
    {
        if (! is_array($mark) || array_is_list($mark) || ! is_string($mark['kind'] ?? null)) {
            throw SyncRejection::invalid('a corrected mark must be an object with a kind');
        }

        if ($mark['kind'] === 'absent') {
            $this->assertObject($mark, ['kind'], 'a corrected absent mark');

            return ['kind' => 'absent'];
        }

        if ($mark['kind'] !== 'score') {
            throw SyncRejection::invalid('a corrected mark is a score or absent');
        }

        $this->assertObject($mark, ['kind', 'value'], 'a corrected score');
        $criterion = Criterion::withTrashed()->find($this->mark->criterion_id);

        if ($criterion === null) {
            throw SyncRejection::invalid('criterionId does not resolve');
        }

        if (! is_int($mark['value']) || $mark['value'] < 0 || $mark['value'] > $criterion->max_score) {
            throw SyncRejection::invalid("a corrected score must be an integer from 0 to {$criterion->max_score}");
        }

        return ['kind' => 'score', 'value' => $mark['value']];
    }

    /**
     * Exactly these keys, nothing more and nothing less.
     *
     * @param  list<string>  $keys
     */
    private function assertObject(mixed $value, array $keys, string $what): void
    {
        $given = is_array($value) && ! array_is_list($value) ? array_keys($value) : null;

        if ($given === null || array_diff($given, $keys) !== [] || array_diff($keys, $given) !== []) {
            throw SyncRejection::invalid("{$what} must be an object with exactly: ".implode(', ', $keys));
        }
    }

    private function assertByIsMe(mixed $byId): void
    {
        if (! is_string($byId) || $byId !== (string) $this->user->id) {
            throw SyncRejection::invalid('byId must be the signed-in user');
        }
    }
}
