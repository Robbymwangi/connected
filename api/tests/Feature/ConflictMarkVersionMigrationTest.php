<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* The backfill of conflicts.mark_version for conflicts that existed before it did (3.2c, G). Rolled back and run
   again inside the test's own transaction: Postgres DDL is transactional, so nothing leaks out of it. */

const MARK_VERSION_MIGRATION = 'database/migrations/2026_10_10_000001_add_mark_version_to_conflicts_table.php';

test('a conflict that existed before the column gets the version its producer wrote, or the safe lower bound', function () {
    $g = buildGraph('School A', '-mv-backfill');
    $b = makeColleague($g, 'b-mv-backfill@example.com');
    postedResults(pushEntries($this, tokenFor($g['teacher']), [pushEntry('marks', $g['mark']->id, 1, ['score' => 6])]));
    app('auth')->forgetGuards();
    postedResults(pushEntries($this, tokenFor($b), [pushEntry('marks', $g['mark']->id, 1, ['score' => 9])]));
    $linked = DB::table('conflicts')->where('mark_id', $g['mark']->id)->value('id');
    // A conflict whose side A has no mutation (made outside sync) on a mark that has since moved on.
    DB::table('marks')->where('id', $g['mark']->id)->update(['version' => 5]);
    $unlinked = openConflictBetween($g, $g['teacher'], $b, ['base_version' => 1])->id;

    Artisan::call('migrate:rollback', ['--path' => MARK_VERSION_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('conflicts', 'mark_version'))->toBeFalse();
    Artisan::call('migrate', ['--path' => MARK_VERSION_MIGRATION, '--force' => true]);

    expect((int) DB::table('conflicts')->where('id', $linked)->value('mark_version'))->toBe(2);
    expect((int) DB::table('conflicts')->where('id', $unlinked)->value('mark_version'))->toBe(2);
});

test('mark_version is required and at least 1', function () {
    $g = buildGraph('School A', '-mv-required');
    $b = makeColleague($g, 'b-mv-required@example.com');
    $conflict = openConflictBetween($g, $g['teacher'], $b);

    expect(fn () => DB::table('conflicts')->where('id', $conflict->id)->update(['mark_version' => 0]))->toThrow(QueryException::class);
    expect(fn () => DB::table('conflicts')->where('id', $conflict->id)->update(['mark_version' => null]))->toThrow(QueryException::class);
});
