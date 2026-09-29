<?php

namespace App\Support;

/* Request-scoped holder for the authenticated account's institution.
   docs/spec/access-model.md, Institution scoping: "An account's institution
   comes from its authenticated token. The client never asserts it." This is
   the one place that fact lives; ResolveInstitution middleware sets it from
   the token, and InstitutionScope reads it back. Bound with scoped()
   (AppServiceProvider) so both sides of one request share the same instance
   and a long-lived worker (Octane, a queue worker) gets a fresh, unset one
   for the next request or job instead of the previous tenant's.

   Two states matter. Unresolved outside HTTP (console, seeder, test) is
   trusted and applies no filter. Unresolved inside HTTP (a request that
   ResolveInstitution has begun but that has no authenticated account) is
   fail-closed: InstitutionScope returns no rows and the creating hook
   refuses. beginRequest() is what tells the two apart. */
class CurrentInstitution
{
    private ?string $id = null;

    private bool $http = false;

    public function set(?string $id): void
    {
        $this->id = $id;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function reset(): void
    {
        $this->id = null;
        $this->http = false;
    }

    public function beginRequest(?string $id): void
    {
        $this->http = true;
        $this->id = $id;
    }

    public function isHttp(): bool
    {
        return $this->http;
    }
}
