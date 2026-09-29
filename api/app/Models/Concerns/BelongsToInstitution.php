<?php

namespace App\Models\Concerns;

use App\Models\Scopes\InstitutionScope;
use App\Support\CurrentInstitution;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/* Every table but institutions carries institution_id (docs/spec/data-model.md,
   Conventions) and is scoped by it (docs/spec/access-model.md, Institution
   scoping). Two halves of the same rule: InstitutionScope closes the read
   side (a query can't see another institution's row), and the creating hook
   below closes the write side ("the client never asserts its institution").

   With no institution resolved, the creating hook leaves institution_id
   exactly as given, same reasoning as InstitutionScope's own no-context
   case: a console command or seeder bootstrapping data outside any request
   is trusted to set it directly. */
trait BelongsToInstitution
{
    protected static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (Model $model) {
            $currentId = app(CurrentInstitution::class)->id();

            if ($currentId === null) {
                return;
            }

            if ($model->institution_id === null) {
                $model->institution_id = $currentId;

                return;
            }

            if ($model->institution_id !== $currentId) {
                throw new InvalidArgumentException(
                    'institution_id must match the authenticated account\'s institution; it is never asserted by the client.',
                );
            }
        });
    }
}
