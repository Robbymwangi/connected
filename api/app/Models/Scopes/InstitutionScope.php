<?php

namespace App\Models\Scopes;

use App\Support\CurrentInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/* docs/spec/access-model.md, Institution scoping: enforcement is one global
   scope, applied once per model, never a `where institution_id = ?` repeated
   in a controller. "A row belonging to another institution is invisible to
   every query an account can run; there is no code path where it becomes
   visible and then gets filtered out afterward."

   With no institution resolved (CurrentInstitution::id() is null), this
   applies no filter at all, not an impossible one: every HTTP request that
   can reach a controller has already gone through ResolveInstitution and
   has a resolved institution, so "no context" only happens in a console
   command, a seeder, or a test that hasn't set one, none of which are the
   controller-forgot-to-scope-a-query surface this scope exists to close.
   docs/spec/access-model.md, Tokens, is explicit that bootstrapping the
   first institution is deliberately out of band, not a request this scope
   ever sees. */
class InstitutionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $institutionId = app(CurrentInstitution::class)->id();

        if ($institutionId !== null) {
            $builder->where($model->qualifyColumn('institution_id'), $institutionId);
        }
    }
}
