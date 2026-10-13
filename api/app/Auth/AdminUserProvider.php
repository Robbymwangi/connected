<?php

namespace App\Auth;

use App\Models\Scopes\InstitutionScope;
use Illuminate\Auth\EloquentUserProvider;

/* The admin panel's user provider (build plan 5.4, ADR 0009). An account is looked up before its
   institution is known: at the panel's login, ResolveInstitution has already begun the request
   with no account, so InstitutionScope fails closed and an ordinary provider would find nobody.
   This lifts that one scope for the user lookup only, the same explicit, narrow bypass
   LoginController makes for the PWA and for the same reason. Every other query in the panel stays
   scoped; soft-deleted accounts stay invisible. */
class AdminUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(InstitutionScope::class);
    }
}
