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
        public readonly ?string $blockedAssessmentId = null,
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

    /* A mark edit refused because its assessment is finalized: an ordinary invalid, plus the id of the
       assessment so the rejection's own transaction can tell the teachers (ServerNotifications). Plain data
       only, no model from the rolled-back transaction. */
    public static function editBlocked(string $assessmentId): self
    {
        return new self('invalid', 'Marks cannot be changed while the assessment is finalized. Unlock it first.', $assessmentId);
    }
}
