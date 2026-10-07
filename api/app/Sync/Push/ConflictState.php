<?php

namespace App\Sync\Push;

use App\Models\Conflict;

/* The part of a stored conflict the policy needs, as plain data so the policy can be tested without a database. */
final class ConflictState
{
    /**
     * @param  list<string>  $proposalByIds  who made each proposal, oldest first
     */
    public function __construct(
        public readonly array $proposalByIds,
        public readonly bool $referred,
        public readonly bool $resolved,
    ) {}

    public static function of(Conflict $conflict): self
    {
        return new self(
            array_values(array_map(fn (array $proposal) => (string) ($proposal['byId'] ?? ''), $conflict->proposals ?? [])),
            $conflict->referral !== null,
            $conflict->resolution !== null || $conflict->resolved_at !== null,
        );
    }
}
