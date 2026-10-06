<?php

use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ADR 0001 rules 2 and 5: the server never assigns version 0, and creating a record
   returns version 1. Version 0 is what a device holds for a record the server has
   never acknowledged, so it must never equal a real record's version: a second
   device creating the same cell at baseVersion 0 would otherwise match rule 2
   (baseVersion equals current) instead of falling through to rule 4 as a conflict
   (docs/spec/sync-protocol.md, rule 3 and the "Two devices create the same cell"
   example). Issue #97. */

test('a row created through its model starts at version 1, and its first update makes it 2', function () {
    $g = buildGraph('School A', '-v1');

    $subject = Subject::create(['institution_id' => $g['institution']->id, 'name' => 'Science']);

    expect($subject->version)->toBe(1);
    expect($subject->fresh()->version)->toBe(1);

    $subject->update(['name' => 'Physics']);

    expect($subject->fresh()->version)->toBe(2);
});

test('every versioned table defaults version to 1, so a raw insert cannot create a version 0 row', function () {
    $tables = collect(Schema::getTables())
        ->pluck('name')
        ->reject(fn ($table) => in_array($table, ['sync_changes', 'sync_mutations'], true)) // their version records another row's version
        ->filter(fn ($table) => Schema::hasColumn($table, 'version'))
        ->values();

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $default = DB::selectOne(
            "select column_default from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = 'version'",
            [$table],
        )->column_default;

        expect((string) $default)->toBe('1', "{$table}.version should default to 1");
    }
});
