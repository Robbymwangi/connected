<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /* No WithoutModelEvents: SchoolSeeder relies on Eloquent events for
       every UUID it generates (HasUuidv7, Mark's deterministic UUIDv5),
       every version default (Syncable), and every institution_id it never
       has to state twice (BelongsToInstitution). Suppressing events would
       leave ids and versions unset and institution_id unguarded, silently
       breaking the entire schema this seeder writes into. */
    public function run(): void
    {
        $this->call(SchoolSeeder::class);
    }
}
