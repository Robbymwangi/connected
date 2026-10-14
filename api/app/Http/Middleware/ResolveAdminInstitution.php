<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* ResolveInstitution for the admin panel's guard, under a name with no argument. The panel registers
   it as persistent middleware so that every Livewire update (a table search, a form save, a modal's
   create) is scoped to the signed-in admin's institution, like the page load before it. Livewire
   re-applies a page's persistent middleware by comparing names with any ":argument" stripped from
   the route's entry but not from its own list, so "ResolveInstitution:admin" never matched and
   updates ran with no institution at all. A class with no argument matches. */
class ResolveAdminInstitution
{
    public function __construct(private readonly ResolveInstitution $resolveInstitution) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->resolveInstitution->handle($request, $next, 'admin');
    }
}
