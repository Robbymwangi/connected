<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/* docs/spec/data-model.md (#34): the API never assigns an id, for any table.
   HasUuids' own creating hook only fills the key when it's still empty, so a
   client-supplied id (a device creating a row offline) is kept exactly as
   given; this trait only changes what the server generates when nothing was
   supplied, from Laravel's default time-ordered UUIDv4 to a real UUIDv7. */
trait HasUuidv7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
