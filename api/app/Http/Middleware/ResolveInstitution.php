<?php

namespace App\Http\Middleware;

use App\Support\CurrentInstitution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* docs/spec/access-model.md, Institution scoping: "An account's institution
   comes from its authenticated token." Appended to the whole api group
   (bootstrap/app.php), not to individual routes, so no route can forget it.
   It runs before the route's own auth middleware, so it resolves the user
   from the sanctum guard itself; the guard caches that user, and auth:sanctum
   later reuses it without a second lookup. That lookup happens before
   beginRequest(), while the context is still unarmed, which is what lets the
   token's own user row load at all.

   From beginRequest() on, an unresolved institution is fail-closed for the
   rest of the request, anonymous requests included: InstitutionScope returns
   nothing rather than every institution's rows. Routes that must read across
   institutions before an account exists (login, #43) say so explicitly with
   withoutGlobalScopes(). */
class ResolveInstitution
{
    public function __construct(private readonly CurrentInstitution $currentInstitution) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentInstitution->reset();

        $user = $request->user('sanctum');

        $this->currentInstitution->beginRequest($user?->institution_id);

        return $next($request);
    }
}
