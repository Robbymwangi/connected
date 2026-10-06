<?php

namespace App\Sync;

use Illuminate\Support\Str;

/* The continuation a paged bootstrap returns instead of a seq (ADR 0010,
   decision 5): the high-water mark fixed when the bootstrap began, and the
   last (table, id) emitted, so the next page resumes after that row with the
   same mark. Only the final page returns the plain integer mark, so a device
   never holds a seq cursor while unsent rows lie at or below it.

   Opaque to the client and unsigned: the institution always comes from the
   auth token, so a tampered cursor can only misdirect the caller's own sync.
   decode() is strict about shape because the table name and id reach a query:
   the table must be one of the synchronisable tables passed in, never a free
   string, and the id must be a UUID. */
final class SyncCursor
{
    public function __construct(
        public readonly int $mark,
        public readonly string $table,
        public readonly string $after,
    ) {}

    public function encode(): string
    {
        $json = json_encode(['m' => $this->mark, 't' => $this->table, 'i' => $this->after], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param  array<int, string>  $tables  the synchronisable table names a cursor may name
     *
     * @throws InvalidSyncCursor
     */
    public static function decode(string $raw, array $tables): self
    {
        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || array_keys($data) !== ['m', 't', 'i']) {
            throw new InvalidSyncCursor('The cursor is not a valid sync cursor.');
        }

        if (! is_int($data['m']) || $data['m'] < 0
            || ! is_string($data['t']) || ! in_array($data['t'], $tables, true)
            || ! is_string($data['i']) || ! Str::isUuid($data['i'])) {
            throw new InvalidSyncCursor('The cursor is not a valid sync cursor.');
        }

        return new self($data['m'], $data['t'], $data['i']);
    }
}
