<?php

namespace App\Sync\Push;

use Illuminate\Support\Str;

/* One outbox entry as it arrived, read defensively: nothing here throws, and
   anything malformed is carried as null or as the raw value for SyncPush's
   envelope check to reject with a reason. The strings that get recorded are held
   to what is safe to store: a table is a lowercase identifier (the driver would
   silently truncate a NUL, and a stored name must be the name sent), and an `at`
   with a NUL is dropped, since `at` is informational and never a reason to reject. The device is not trusted to send a
   well-formed entry, only to be answered about it.

   payloadHash is the replay identity: a hash of the table, record id,
   baseVersion, and fields exactly as decoded, with keys sorted at every level so
   key order does not matter. `id` is the key it is stored under, and `at` is the
   device's informational claim; neither is part of it, so a resend that differs
   only in `at` is still the same entry. A known id arriving with a different
   hash is a different request wearing a used id, and is rejected, because
   answering it `replayed` would silently drop whatever the device had added. */
final class PushEntry
{
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $recordId,
        public readonly ?string $table,
        public readonly mixed $baseVersion,
        public readonly mixed $fields,
        public readonly ?string $at,
        public readonly string $payloadHash,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function from(array $raw): self
    {
        $table = $raw['table'] ?? null;
        $at = $raw['at'] ?? null;

        return new self(
            id: self::uuid($raw['id'] ?? null),
            recordId: self::uuid($raw['recordId'] ?? null),
            table: is_string($table) && preg_match('/^[a-z_]{1,64}$/', $table) === 1 ? $table : null,
            baseVersion: $raw['baseVersion'] ?? null,
            fields: $raw['fields'] ?? null,
            at: is_string($at) && strlen($at) <= 64 && ! str_contains($at, "\0") ? $at : null,
            payloadHash: self::hash($raw),
        );
    }

    /** An entry that cannot be keyed cannot be recorded, so it is answered and forgotten. */
    public function isRecordable(): bool
    {
        return $this->id !== null && $this->recordId !== null && $this->table !== null;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function hash(array $raw): string
    {
        $identity = self::canonical([
            'baseVersion' => $raw['baseVersion'] ?? null,
            'fields' => $raw['fields'] ?? null,
            'recordId' => $raw['recordId'] ?? null,
            'table' => $raw['table'] ?? null,
        ]);

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /* Keys sorted at every level; a list keeps its order, since order is part of
       what a list says. The hash is only ever compared with another hash made
       the same way here, so it needs to be deterministic, not reversible. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
