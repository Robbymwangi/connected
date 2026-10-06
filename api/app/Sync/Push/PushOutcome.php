<?php

namespace App\Sync\Push;

use App\Models\SyncMutation;

/* The result of one push entry, in the shape POST /sync returns it
   (docs/spec/sync-protocol.md). Keys appear only where they mean something:
   a version for accepted and merged, the current row (and, for a mark in 3.2b,
   a conflict id) for a conflict, a reason for invalid, nothing more for
   forbidden. A replay carries the original answer plus `replayed: true`. */
final class PushOutcome
{
    /**
     * @param  array<string, mixed>|null  $current
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $status,
        public readonly ?int $version = null,
        public readonly ?string $conflictId = null,
        public readonly ?string $reason = null,
        public readonly ?array $current = null,
        public readonly bool $replayed = false,
    ) {}

    public static function accepted(string $id, int $version): self
    {
        return new self($id, 'accepted', version: $version);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    public static function conflict(string $id, array $current): self
    {
        return new self($id, 'conflict', current: $current);
    }

    public static function rejected(?string $id, SyncRejection $rejection): self
    {
        return new self($id, $rejection->status, reason: $rejection->reasonText);
    }

    /**
     * The stored outcome of an earlier decision, read back for a resend.
     *
     * @param  array<string, mixed>|null  $current
     */
    public static function fromStored(SyncMutation $mutation, ?array $current): self
    {
        return new self(
            $mutation->id,
            $mutation->status,
            version: $mutation->status === 'conflict' ? null : $mutation->version,
            conflictId: $mutation->conflict_id,
            reason: $mutation->reason,
            current: $mutation->status === 'conflict' ? $current : null,
            replayed: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['id' => $this->id, 'status' => $this->status];

        if (in_array($this->status, ['accepted', 'merged'], true)) {
            $result['version'] = $this->version;
        }

        if ($this->status === 'conflict') {
            if ($this->conflictId !== null) {
                $result['conflictId'] = $this->conflictId;
            }
            $result['current'] = $this->current;
        }

        if ($this->status === 'invalid') {
            $result['reason'] = $this->reason;
        }

        if ($this->replayed) {
            $result['replayed'] = true;
        }

        return $result;
    }
}
