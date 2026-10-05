<?php

use Carbon\CarbonImmutable;

test('pull returns only newer rows in the institution and includes soft-deleted rows', function () {
    $start = CarbonImmutable::parse('2026-10-01T08:00:00Z');
    CarbonImmutable::setTestNow($start);

    $schoolA = buildGraph('School A', '-sync-a');
    $schoolB = buildGraph('School B', '-sync-b');
    $token = tokenFor($schoolA['teacher']);

    $firstPull = $this->withToken($token)
        ->getJson('/api/sync?since='.$start->toISOString())
        ->assertOk()
        ->assertJsonCount(0, 'changes');

    $cursor = $firstPull->json('cursor');
    CarbonImmutable::setTestNow($start->addMinute());

    $schoolA['subject']->update(['name' => 'Changed subject']);
    $schoolA['mark']->update(['score' => 9]);
    $schoolA['student']->delete();
    $schoolB['subject']->update(['name' => 'Other school change']);

    $secondPull = $this->withToken($token)
        ->getJson('/api/sync?since='.urlencode($cursor))
        ->assertOk();

    expect($secondPull->json('changes'))
        ->toHaveCount(3)
        ->and(collect($secondPull->json('changes'))->pluck('recordId')->all())
        ->toContain($schoolA['subject']->id, $schoolA['mark']->id, $schoolA['student']->id)
        ->not->toContain($schoolB['subject']->id);

    $deletedStudent = collect($secondPull->json('changes'))
        ->firstWhere('recordId', $schoolA['student']->id);

    expect($deletedStudent['record']['deleted_at'])->not->toBeNull()
        ->and($secondPull->json('cursor'))->toBeString();

    CarbonImmutable::setTestNow();
});
