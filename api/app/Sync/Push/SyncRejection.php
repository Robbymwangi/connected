<?php

namespace App\Sync\Push;

use RuntimeException;

/* A decided rejection of one push entry: forbidden (the actor or the record
   itself is out of bounds) or invalid (with a reason the device can show).
   Thrown, never caught inside the entry's own transaction, so that transaction
   rolls back and the rejection is recorded afterwards in a fresh one
   (SyncPush::reject). Catching it inside would leave nothing to roll back to. */
final class SyncRejection extends RuntimeException
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $reasonText = null,
    ) {
        parent::__construct($reasonText ?? $status);
    }

    public static function forbidden(): self
    {
        return new self('forbidden');
    }

    public static function invalid(string $reason): self
    {
        return new self('invalid', $reason);
    }
}
