<?php

test('analytics summary is authenticated, institution-scoped, and provides data-backed filters', function () {
    $graph = buildGraph();
    $graph['assessment']->update(['status' => 'finalized']);

    $other = buildGraph('Other School', '-other');
    $other['assessment']->update(['status' => 'finalized', 'year' => 2025]);

    $this->getJson('/api/reports/summary?stream=West&year=2026')
        ->assertUnauthorized();

    $response = $this->withToken(tokenFor($graph['teacher']))
        ->getJson('/api/reports/summary?stream=West&subject_id='.$graph['subject']->id.'&year=2026&term=1&assessment_name=CAT%201');

    $response->assertOk()
        ->assertJsonPath('available_years', [2026])
        ->assertJsonPath('available_assessments', ['CAT 1'])
        ->assertJsonPath('summary.pass_rate', 100)
        ->assertJsonPath('summary.mean_score', 80)
        ->assertJsonPath('summary.performance_levels.EE', 1)
        ->assertJsonPath('summary.criterion_breakdown.0.name', 'Accuracy')
        ->assertJsonPath('summary.criterion_breakdown.0.pct', 80);

    $overall = $this->withToken(tokenFor($graph['teacher']))
        ->getJson('/api/reports/summary?stream=West&year=2026&term=1');

    $overall->assertOk()->assertJsonPath('summary.criterion_breakdown', []);
});
