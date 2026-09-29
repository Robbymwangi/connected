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

   Outside HTTP with no institution resolved (console command, seeder, test)
   this applies no filter: that is the trusted, out-of-band path, and
   docs/spec/access-model.md, Tokens, is explicit that bootstrapping the
   first institution is not a request this scope ever sees. Inside HTTP
   (ResolveInstitution has begun the request) with no institution, it fails
   closed and matches no rows, so a request without an authenticated account
   can never fall through to every institution's data. */
class InstitutionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $institutionId = app(CurrentInstitution::class)->id();

        if ($institutionId !== null) {
            $builder->where($model->qualifyColumn('institution_id'), $institutionId);
        } elseif (app(CurrentInstitution::class)->isHttp()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
