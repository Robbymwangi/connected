<?php

namespace App\Support;

/* Request-scoped holder for the authenticated account's institution.
   docs/spec/access-model.md, Institution scoping: "An account's institution
   comes from its authenticated token. The client never asserts it." This is
   the one place that fact lives; ResolveInstitution middleware sets it from
   the token, and InstitutionScope reads it back. Bound as a singleton
   (AppServiceProvider) so both sides of one request share the same
   instance; Laravel resets the container between test runs, so it starts
   unset for every test and every console invocation. */
class CurrentInstitution
{
    private ?string $id = null;

    public function set(?string $id): void
    {
        $this->id = $id;
    }

    public function id(): ?string
    {
        return $this->id;
    }
}
