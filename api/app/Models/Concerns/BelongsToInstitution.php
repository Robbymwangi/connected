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

   With no institution resolved outside HTTP, the creating hook leaves
   institution_id exactly as given, same reasoning as InstitutionScope's own
   no-context case: a console command or seeder bootstrapping data outside
   any request is trusted to set it directly. Inside HTTP with none resolved
   it refuses, matching the scope's fail-closed read side.

   The updating hook makes institution_id immutable once a row exists, so an
   update cannot move a row to another institution. Query-level bulk updates
   (Model::where(...)->update()) fire no model events and are not covered;
   sync writes go through our own server code, not arbitrary bulk updates. */
trait BelongsToInstitution
{
    protected static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (Model $model) {
            $currentId = app(CurrentInstitution::class)->id();

            if ($currentId === null) {
                if (app(CurrentInstitution::class)->isHttp()) {
                    throw new InvalidArgumentException(
                        'No institution is resolved for this request; a row cannot be created without one.',
                    );
                }

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

        static::updating(function (Model $model) {
            if ($model->isDirty('institution_id')) {
                throw new InvalidArgumentException('institution_id is immutable once a row exists.');
            }
        });
    }
}
