<?php

namespace App\Http\Middleware;

use App\Support\CurrentInstitution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* docs/spec/access-model.md, Institution scoping: "An account's institution
   comes from its authenticated token." Runs after auth:sanctum, so
   $request->user() is already resolved; this is the only place that reads
   it off the user and hands it to CurrentInstitution, which
   InstitutionScope and BelongsToInstitution's creating hook then read back.
   Not yet attached to a route group: no protected route exists before #43
   (Authentication) and #48 (Read endpoints) build one. Registered here so
   those tickets have a named middleware to reach for rather than inventing
   their own. */
class ResolveInstitution
{
    public function __construct(private readonly CurrentInstitution $currentInstitution) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $this->currentInstitution->set($user->institution_id);
        }

        return $next($request);
    }
}
