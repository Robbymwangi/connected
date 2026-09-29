<?php

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* The tenant boundary itself: not scoped by institution_id and not
   synchronisable, so no version and no SoftDeletes. docs/spec/data-model.md
   (#34), Conventions. */
#[Fillable(['id', 'name'])]
class Institution extends Model
{
    use HasUuidv7;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
