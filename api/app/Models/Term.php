<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use App\Support\SyncLog;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* The term calendar (ADR 0014, decision 4). A term has no planned dates: an admin opens it when
   the school has opened, and the row records when that happened and who said so. The rules live
   here, once, for the panel and anything after it:

   - At most one term is open per institution (a partial unique index backs this).
   - A term opened comes after every term already recorded, in the order Term 1, 2, 3, then Term 1
     of the next year. It need not be the very next one: a school that skipped the system for a
     term is not stuck. The year is what the admin names, never one read from a clock.
   - Opening a term ends the one open, at the same instant; an admin may also end the open term
     with none to follow, for the holidays.
   - The open term may be withdrawn while no assessment names it. That soft deletes it and reopens
     the term its opening ended, if it ended one. Nothing else reopens a term.

   What an open term governs (which term a new assessment takes) is ADR 0014's next merge request;
   nothing here reads it yet. Every write runs inside SyncLog::transaction, behind the institution's
   advisory lock, so two admins opening terms at once are serialised and the second sees the
   first's term. Synchronisable and pull-only. */
#[Fillable(['id', 'institution_id', 'year', 'term', 'opened_at', 'opened_by', 'ended_at', 'ended_by'])]
class Term extends Model
{
    use BelongsToInstitution, Syncable;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'term' => 'integer',
            'opened_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /* Each query names its institution rather than leaning on the global scope, which filters by
       the request's institution and by none outside a request (a seeder, a console command). */

    /** The institution's open term, if one is. */
    public static function current(string $institutionId): ?self
    {
        return self::query()->where('institution_id', $institutionId)->whereNull('ended_at')->first();
    }

    /** The latest term recorded, open or ended, by its place in the calendar rather than when it was written. */
    public static function lastRecorded(string $institutionId): ?self
    {
        return self::query()->where('institution_id', $institutionId)->orderByDesc('year')->orderByDesc('term')->first();
    }

    /**
     * The term after the latest recorded one, which is what an admin opening a term usually means.
     *
     * @return array{year: int|null, term: int|null}
     */
    public static function suggestedNext(string $institutionId): array
    {
        $last = self::lastRecorded($institutionId);

        if ($last === null) {
            return ['year' => null, 'term' => null];
        }

        return $last->term === 3
            ? ['year' => $last->year + 1, 'term' => 1]
            : ['year' => $last->year, 'term' => $last->term + 1];
    }

    public function label(): string
    {
        return "Term {$this->term}, {$this->year}";
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public static function open(User $by, int $year, int $term): self
    {
        self::authorizeAdmin($by);

        if ($term < 1 || $term > 3 || $year < 1) {
            throw new DomainException('A term is Term 1, 2, or 3 of a year.');
        }

        return SyncLog::transaction($by->institution_id, function () use ($by, $year, $term): self {
            $last = self::lastRecorded($by->institution_id);

            if ($last !== null && [$year, $term] <= [$last->year, $last->term]) {
                throw new DomainException("Term {$term}, {$year} does not come after {$last->label()}, the latest term recorded.");
            }

            $now = now();
            self::current($by->institution_id)?->update(['ended_at' => $now, 'ended_by' => $by->id]);

            return self::create([
                'institution_id' => $by->institution_id,
                'year' => $year,
                'term' => $term,
                'opened_at' => $now,
                'opened_by' => $by->id,
            ]);
        });
    }

    /** Ends the open term with none to follow: the school has closed for the holidays. */
    public static function endCurrent(User $by): self
    {
        self::authorizeAdmin($by);

        return SyncLog::transaction($by->institution_id, function () use ($by): self {
            $current = self::current($by->institution_id) ?? throw new DomainException('No term is open.');
            $current->update(['ended_at' => now(), 'ended_by' => $by->id]);

            return $current;
        });
    }

    /* For a term opened by mistake. The term its opening ended is the one whose ended_at is this
       term's opened_at, since open() writes both from one instant; a term an admin had already ended
       for the holidays stays ended. */
    public function withdraw(User $by): void
    {
        self::authorizeAdmin($by);

        if ($by->institution_id !== $this->institution_id) {
            throw new AuthorizationException('Only an admin keeps the term calendar.');
        }

        SyncLog::transaction($this->institution_id, function (): void {
            $this->refresh();

            if (! $this->isOpen()) {
                throw new DomainException('Only the open term can be withdrawn.');
            }

            if (Assessment::query()->where('institution_id', $this->institution_id)->where('year', $this->year)->where('term', $this->term)->exists()) {
                throw new DomainException("{$this->label()} already has assessments, so it cannot be withdrawn.");
            }

            $this->delete();

            if ($this->opened_at !== null) {
                self::query()
                    ->where('institution_id', $this->institution_id)
                    ->whereKeyNot($this->getKey())
                    ->where('ended_at', $this->opened_at)
                    ->first()
                    ?->update(['ended_at' => null, 'ended_by' => null]);
            }
        });
    }

    /* The calendar is the back office's to keep (ADR 0014, decision 7): an admin account, active.
       Not a policy, because the panel would then ask it for every page and none of those questions
       is the one that matters here. */
    private static function authorizeAdmin(User $by): void
    {
        if (! $by->isAdmin() || $by->deactivated_at !== null) {
            throw new AuthorizationException('Only an admin keeps the term calendar.');
        }
    }
}
