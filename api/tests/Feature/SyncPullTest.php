<?php

use App\Models\Concerns\Syncable;
use App\Models\Notification;
use App\Models\SyncChange;
use App\Models\User;
use App\Support\CurrentInstitution;
use App\Sync\SyncPull;
use Illuminate\Support\Facades\File;

/* Slice 3 of 3.1 (ADR 0010): GET /sync reads the change log by seq, and a
   first pull (no `since`) reads the live tables, paged by an opaque
   continuation. The cursor is never a timestamp. */

function pull(mixed $test, string $token, array $query = [])
{
    return $test->withToken($token)->getJson('/api/sync?'.http_build_query($query));
}

/** Drain a bootstrap page by page; returns [changes, finalCursor, pages]. */
function drainBootstrap(mixed $test, string $token, int $limit): array
{
    $changes = [];
    $query = ['limit' => $limit];
    $pages = 0;

    do {
        $response = pull($test, $token, $query)->assertOk();
        $changes = array_merge($changes, $response->json('changes'));
        $query = ['limit' => $limit, 'since' => $response->json('cursor')];
        $pages++;
    } while ($response->json('more') && $pages < 200);

    return [$changes, $response->json('cursor'), $pages];
}

function keyed(array $changes): array
{
    return collect($changes)->mapWithKeys(fn ($c) => [$c['table'].'/'.$c['recordId'] => $c])->all();
}

test('pull, write on the server, pull again: exactly the changed rows and nothing else', function () {
    $g = buildGraph('School A', '-done');
    $token = tokenFor($g['teacher']);

    $first = pull($this, $token)->assertOk();
    $cursor = $first->json('cursor');
    expect($cursor)->toBeInt();
    expect($first->json('more'))->toBeFalse();

    $g['subject']->update(['name' => 'Changed subject']);
    $g['mark']->update(['score' => 9]);
    $g['student']->delete();

    $second = pull($this, $token, ['since' => $cursor])->assertOk();
    $changes = $second->json('changes');

    expect($changes)->toHaveCount(3);
    expect(array_column($changes, 'seq'))->toBe(collect(array_column($changes, 'seq'))->sort()->values()->all());
    $byKey = keyed($changes);
    expect($byKey['subjects/'.$g['subject']->id])->toMatchArray(['version' => 2, 'fields' => ['name' => 'Changed subject']]);
    expect($byKey['marks/'.$g['mark']->id])->toMatchArray(['version' => 2, 'fields' => ['score' => 9]]);
    expect(array_keys($byKey['students/'.$g['student']->id]['fields']))->toBe(['deletedAt']);
    expect($byKey['students/'.$g['student']->id]['fields']['deletedAt'])->not->toBeNull();
    expect($second->json('cursor'))->toBe(max(array_column($changes, 'seq')));
    expect($second->json('more'))->toBeFalse();
});

test('an empty pull leaves the cursor where it was', function () {
    $g = buildGraph('School A', '-empty');
    $token = tokenFor($g['teacher']);
    $cursor = pull($this, $token)->json('cursor');

    $again = pull($this, $token, ['since' => $cursor])->assertOk();

    expect($again->json('changes'))->toBe([]);
    expect($again->json('cursor'))->toBe($cursor);
    expect($again->json('more'))->toBeFalse();
});

test('an explicit since of 0 is a log pull, never a bootstrap, even when the log is empty', function () {
    $g = buildGraph('School A', '-zero');
    SyncChange::query()->delete();

    $response = pull($this, tokenFor($g['teacher']), ['since' => 0])->assertOk();

    expect($response->json('changes'))->toBe([]);
    expect($response->json('cursor'))->toBe(0);
    expect($response->json('more'))->toBeFalse();
});

test('a log pull pages by limit without losing or repeating a change', function () {
    $g = buildGraph('School A', '-paging');
    $token = tokenFor($g['teacher']);
    $cursor = pull($this, $token)->json('cursor');

    foreach (range(1, 5) as $n) {
        $g['subject']->update(['name' => "Name {$n}"]);
    }

    $seen = [];
    $query = ['since' => $cursor, 'limit' => 2];
    do {
        $page = pull($this, $token, $query)->assertOk();
        expect(count($page->json('changes')))->toBeLessThanOrEqual(2);
        $seen = array_merge($seen, array_column($page->json('changes'), 'seq'));
        $query['since'] = $page->json('cursor');
    } while ($page->json('more'));

    expect($seen)->toHaveCount(5);
    expect(array_unique($seen))->toHaveCount(5);
});

test('field names are camelCased on the wire, and nested JSON passes through as stored', function () {
    $g = buildGraph('School A', '-camel');
    $token = tokenFor($g['teacher']);
    $cursor = pull($this, $token)->json('cursor');

    $g['mark']->update(['mark_kind' => 'absent', 'score' => null]);

    $change = pull($this, $token, ['since' => $cursor])->json('changes.0');

    expect($change['fields'])->toHaveCount(2)->toMatchArray(['markKind' => 'absent', 'score' => null]);
});

test('another institution never appears, in a log pull or a bootstrap', function () {
    $a = buildGraph('School A', '-iso-a');
    $b = buildGraph('School B', '-iso-b');
    $token = tokenFor($a['teacher']);

    $bootstrap = pull($this, $token)->assertOk();
    $cursor = $bootstrap->json('cursor');
    // The request left school A as the current institution; a write for school B
    // arrives from another process, which starts with none.
    app(CurrentInstitution::class)->reset();
    $b['subject']->update(['name' => 'Other school change']);
    $log = pull($this, $token, ['since' => $cursor])->assertOk();

    $ids = array_column($bootstrap->json('changes'), 'recordId');
    expect($ids)->not->toContain($b['subject']->id)->not->toContain($b['mark']->id);
    expect($ids)->toContain($a['subject']->id);
    expect($log->json('changes'))->toBe([]);
});

test('a bootstrap carries every live and soft-deleted row once and never a hidden attribute', function () {
    $g = buildGraph('School A', '-boot');
    $g['student']->delete();

    $response = pull($this, tokenFor($g['teacher']))->assertOk();
    $changes = $response->json('changes');
    $byKey = keyed($changes);

    expect($byKey)->toHaveCount(count($changes));
    expect($byKey)->toHaveKeys([
        'subjects/'.$g['subject']->id,
        'marks/'.$g['mark']->id,
        'students/'.$g['student']->id,
        'users/'.$g['teacher']->id,
    ]);
    expect($byKey['students/'.$g['student']->id]['fields']['deletedAt'])->not->toBeNull();
    expect($byKey['users/'.$g['teacher']->id]['fields'])->not->toHaveKey('password');
    expect($byKey['marks/'.$g['mark']->id]['fields'])->toHaveKey('markKind');
    expect($byKey['marks/'.$g['mark']->id]['version'])->toBe($g['mark']->fresh()->version);
    expect($response->json('cursor'))->toBe((int) SyncChange::max('seq'));
});

test('a paged bootstrap yields the same rows as an unpaged one, ending on a plain seq', function () {
    $g = buildGraph('School A', '-pagedboot');
    $token = tokenFor($g['teacher']);
    $unpaged = pull($this, $token)->json();

    [$changes, $finalCursor, $pages] = drainBootstrap($this, $token, 3);

    expect($pages)->toBeGreaterThan(1);
    expect(array_keys(keyed($changes)))->toEqualCanonicalizing(array_keys(keyed($unpaged['changes'])));
    expect(count($changes))->toBe(count($unpaged['changes']));
    expect($finalCursor)->toBeInt()->toBe($unpaged['cursor']);
});

test('a write between bootstrap pages arrives on the next ordinary pull', function () {
    $g = buildGraph('School A', '-between');
    $token = tokenFor($g['teacher']);

    $page = pull($this, $token, ['limit' => 3])->assertOk();
    expect($page->json('more'))->toBeTrue();
    expect($page->json('cursor'))->toBeString();

    $g['subject']->update(['name' => 'Renamed mid-bootstrap']);

    $query = ['limit' => 3, 'since' => $page->json('cursor')];
    do {
        $page = pull($this, $token, $query)->assertOk();
        $query['since'] = $page->json('cursor');
    } while ($page->json('more'));
    $finalCursor = $page->json('cursor');

    $next = pull($this, $token, ['since' => $finalCursor])->assertOk();
    expect($next->json('changes'))->toHaveCount(1);
    expect($next->json('changes.0.fields'))->toBe(['name' => 'Renamed mid-bootstrap']);
});

test('notifications are pulled only by the user they belong to', function () {
    $g = buildGraph('School A', '-notif');
    $colleague = User::create([
        'institution_id' => $g['institution']->id,
        'name' => 'Colleague',
        'email' => 'colleague-notif@example.com',
        'password' => 'a-hashed-password',
    ]);
    $mine = Notification::create(['institution_id' => $g['institution']->id, 'user_id' => $g['teacher']->id, 'kind' => 'sync-conflict', 'tone' => 'warning', 'title' => 'Mine', 'body' => 'Mine', 'unread' => true]);
    $theirs = Notification::create(['institution_id' => $g['institution']->id, 'user_id' => $colleague->id, 'kind' => 'sync-conflict', 'tone' => 'warning', 'title' => 'Theirs', 'body' => 'Theirs', 'unread' => true]);
    $token = tokenFor($g['teacher']);

    $bootstrap = pull($this, $token)->assertOk();
    $ids = array_column($bootstrap->json('changes'), 'recordId');
    expect($ids)->toContain($mine->id)->not->toContain($theirs->id);

    $cursor = $bootstrap->json('cursor');
    $theirs->update(['unread' => false]);
    $mine->update(['unread' => false]);

    $log = pull($this, $token, ['since' => $cursor])->assertOk();
    expect(array_column($log->json('changes'), 'recordId'))->toBe([$mine->id]);
});

test('pull needs a token, and rejects a bad since or limit', function () {
    $g = buildGraph('School A', '-validate');
    $token = tokenFor($g['teacher']);

    $this->getJson('/api/sync')->assertUnauthorized();

    foreach ([['since' => 'abc'], ['since' => '-1'], ['since' => 'eyJtIjoxfQ'], ['limit' => 0], ['limit' => 1001], ['limit' => 'x']] as $query) {
        pull($this, $token, $query)->assertUnprocessable();
    }
});

test('the bootstrap table list is exactly the Syncable models, so no table is silently left out', function () {
    $syncable = collect(File::files(app_path('Models')))
        ->map(fn ($file) => 'App\\Models\\'.$file->getBasename('.php'))
        ->filter(fn ($class) => in_array(Syncable::class, class_uses_recursive($class), true))
        ->sort()
        ->values()
        ->all();

    expect(collect(SyncPull::MODELS)->sort()->values()->all())->toBe($syncable);
});
