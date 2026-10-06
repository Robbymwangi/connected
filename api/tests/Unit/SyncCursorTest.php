<?php

use App\Sync\InvalidSyncCursor;
use App\Sync\SyncCursor;

/* The opaque continuation a paged bootstrap hands back (ADR 0010, decision 5):
   the fixed high-water mark plus the last (table, id) read. It is unsigned on
   purpose, since the institution always comes from the auth token and a
   tampered cursor can only misdirect the caller's own sync, so what matters is
   that a malformed one is rejected and never reaches a query. */

const CURSOR_TABLES = ['marks', 'students'];
const CURSOR_ID = '0190b0d0-7c3a-7000-8000-000000000001';

test('a cursor round-trips through its opaque encoding', function () {
    $cursor = new SyncCursor(42, 'marks', CURSOR_ID);

    $decoded = SyncCursor::decode($cursor->encode(), CURSOR_TABLES);

    expect($decoded->mark)->toBe(42);
    expect($decoded->table)->toBe('marks');
    expect($decoded->after)->toBe(CURSOR_ID);
});

test('an encoded cursor is URL-safe and never a bare integer', function () {
    $encoded = (new SyncCursor(7, 'students', CURSOR_ID))->encode();

    expect($encoded)->toMatch('/^[A-Za-z0-9_-]+$/');
    expect(ctype_digit($encoded))->toBeFalse();
});

test('a malformed cursor is rejected', function (string $raw) {
    expect(fn () => SyncCursor::decode($raw, CURSOR_TABLES))->toThrow(InvalidSyncCursor::class);
})->with([
    'not base64' => ['!!!not-a-cursor!!!'],
    'not json' => [rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')],
    'json but not an object of the right keys' => [rtrim(strtr(base64_encode('{"m":1}'), '+/', '-_'), '=')],
    'extra keys' => [rtrim(strtr(base64_encode('{"m":1,"t":"marks","i":"'.CURSOR_ID.'","x":1}'), '+/', '-_'), '=')],
    'mark is not an integer' => [rtrim(strtr(base64_encode('{"m":"1","t":"marks","i":"'.CURSOR_ID.'"}'), '+/', '-_'), '=')],
    'negative mark' => [rtrim(strtr(base64_encode('{"m":-1,"t":"marks","i":"'.CURSOR_ID.'"}'), '+/', '-_'), '=')],
    'table not in the list' => [rtrim(strtr(base64_encode('{"m":1,"t":"users; drop table marks","i":"'.CURSOR_ID.'"}'), '+/', '-_'), '=')],
    'id is not a uuid' => [rtrim(strtr(base64_encode('{"m":1,"t":"marks","i":"1 or 1=1"}'), '+/', '-_'), '=')],
]);
