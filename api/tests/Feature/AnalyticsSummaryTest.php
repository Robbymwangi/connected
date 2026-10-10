<?php

use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Result;
use App\Models\Student;

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

test('an exact assessment summary includes every enrolled student and uses the stored result maximum', function () {
    $graph = buildGraph();
    $graph['assessment']->update(['status' => 'finalized']);

    $addStudent = function (string $name) use ($graph): Student {
        $student = Student::create([
            'institution_id' => $graph['institution']->id,
            'name' => $name,
            'gender' => 'F',
            'dob' => '2015-01-01',
        ]);
        Enrolment::create([
            'institution_id' => $graph['institution']->id,
            'student_id' => $student->id,
            'class_id' => $graph['class']->id,
            'year' => 2026,
        ]);

        return $student;
    };
    $fallback = $addStudent('S. Fallback');
    $absent = $addStudent('S. Absent');
    $missing = $addStudent('S. Missing');

    Mark::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $fallback->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'empty',
        'score' => null,
        'last_edited_by' => $graph['teacher']->id,
    ]);
    Mark::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $absent->id,
        'criterion_id' => $graph['criterion']->id,
        'mark_kind' => 'absent',
        'score' => null,
        'last_edited_by' => $graph['teacher']->id,
    ]);
    Result::create([
        'institution_id' => $graph['institution']->id,
        'assessment_id' => $graph['assessment']->id,
        'student_id' => $fallback->id,
        'total' => 18,
        'max' => 30,
        'level' => 'ME',
    ]);

    $response = $this->withToken(tokenFor($graph['teacher']))
        ->getJson('/api/reports/summary?stream=West&subject_id='.$graph['subject']->id.'&year=2026&assessment_id='.$graph['assessment']->id);

    $response->assertOk()->assertJsonPath('filters.assessment_id', $graph['assessment']->id);
    $rows = collect($response->json('student_outcomes'))->keyBy('studentId');

    expect($rows)->toHaveCount(4)
        ->and($rows[$graph['student']->id])->toMatchArray([
            'status' => 'scored', 'total' => 8, 'max' => 10, 'pct' => 80.0, 'level' => 'EE',
        ])
        ->and($rows[$fallback->id])->toMatchArray([
            'status' => 'scored', 'total' => 18, 'max' => 30, 'pct' => 60.0, 'level' => 'ME',
        ])
        ->and($rows[$absent->id])->toMatchArray([
            'status' => 'absent', 'total' => null, 'max' => null, 'pct' => null, 'level' => null,
        ])
        ->and($rows[$missing->id])->toMatchArray([
            'status' => 'missing', 'total' => null, 'max' => null, 'pct' => null, 'level' => null,
        ]);

    $this->withToken(tokenFor($graph['teacher']))
        ->getJson('/api/reports/summary?stream=West&subject_id='.$graph['subject']->id.'&year=2026')
        ->assertOk()
        ->assertJsonPath('student_outcomes', []);
});
