<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\Syncable;
use App\Support\SyncLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/* One row is one sitting of one subject in one stream. status only ever
   stores scheduled, finalized, and reports-generated, the three states
   something actually writes: in-progress and complete are derived from the
   grid's own completeness every time it's read (docs/spec/workflow.md,
   #37), never a value set here. created_by scopes lifecycle actions (edit,
   finalize, unlock) to the creator or an assigned teacher (#36 Option B).
   docs/spec/data-model.md (#34). */
#[Fillable([
    'id', 'institution_id', 'class_id', 'subject_id', 'name', 'term', 'year',
    'date', 'status', 'created_by', 'finalized_at', 'finalized_by',
])]
class Assessment extends Model
{
    use BelongsToInstitution, Syncable;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'finalized_at' => 'datetime',
        ];
    }

    public function finalize(User $user): void
    {
        $assessment = SyncLog::transaction($this->institution_id, function () use ($user): self {
            $assessment = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            Gate::forUser($user)->authorize('finalize', $assessment);

            if ($assessment->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'status' => 'The assessment must be unlocked before it can be finalized again.',
                ]);
            }

            $markIds = $assessment->marks()->orderBy('id')->lockForUpdate()->pluck('id');

            if (Conflict::query()->whereIn('mark_id', $markIds)->whereNull('resolved_at')->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'An assessment with open mark conflicts cannot be finalized.',
                ]);
            }

            $assessment->fill([
                'status' => 'finalized',
                'finalized_at' => now(),
                'finalized_by' => $user->id,
            ])->save();

            return $assessment;
        });

        $this->setRawAttributes($assessment->getAttributes(), true);
        $this->unsetRelations();
    }

    public function unlock(User $user, ?string $note = null): void
    {
        $assessment = SyncLog::transaction($this->institution_id, function () use ($user, $note): self {
            $assessment = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            Gate::forUser($user)->authorize('unlock', $assessment);

            if (! in_array($assessment->status, ['finalized', 'reports-generated'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only a finalized assessment can be unlocked.',
                ]);
            }

            $reports = $assessment->reports()->orderBy('id')->lockForUpdate()->get();
            $note = trim($note ?? '');

            if ($reports->isNotEmpty() && $note === '') {
                throw ValidationException::withMessages([
                    'note' => 'A resolution note is required when reports exist.',
                ]);
            }

            if ($reports->isNotEmpty()) {
                $assessment->unlockNotes()->create([
                    'institution_id' => $assessment->institution_id,
                    'user_id' => $user->id,
                    'note' => $note,
                ]);
            }

            foreach ($assessment->comments()->orderBy('id')->lockForUpdate()->get() as $comment) {
                $comment->delete();
            }

            foreach ($reports as $report) {
                $report->delete();
            }

            $assessment->fill([
                'status' => 'scheduled',
                'finalized_at' => null,
                'finalized_by' => null,
            ])->save();

            return $assessment;
        });

        $this->setRawAttributes($assessment->getAttributes(), true);
        $this->unsetRelations();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function unlockNotes(): HasMany
    {
        return $this->hasMany(UnlockNote::class);
    }
}
