<?php

use App\Models\Assessment;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Result;
use App\Models\Student;
use App\Support\AnalyticsFilters;
use App\Support\AnalyticsQueryService;

test('analytics queries match hand-calculated values and respect outcome rules', function () {
    $graph = buildGraph();
    $institution = $graph['institution'];
    $teacher = $graph['teacher'];
    $subject = $graph['subject'];
    $criterion = $graph['criterion'];
    $class = $graph['class'];
    $firstAssessment = $graph['assessment'];
    $firstAssessment->update(['status' => 'finalized']);

    $students = [$graph['student']];
    foreach (['Absent', 'Missing', 'Fallback'] as $name) {
        $student = Student::create([
            'institution_id' => $institution->id,
            'name' => "S. {$name}",
            'gender' => 'F',
            'dob' => '2015-01-01',
        ]);
        Enrolment::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'year' => 2026,
        ]);
        $students[] = $student;
    }

    $secondAssessment = Assessment::create([
        'institution_id' => $institution->id,
        'class_id' => $class->id,
        'subject_id' => $subject->id,
        'name' => 'CAT 2',
        'term' => 1,
        'year' => 2026,
        'date' => '2026-03-01',
        'status' => 'finalized',
        'created_by' => $teacher->id,
    ]);

    $mark = function (Assessment $assessment, Student $student, string $kind, ?int $score = null) use ($institution, $criterion, $teacher): void {
        Mark::create([
            'institution_id' => $institution->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'criterion_id' => $criterion->id,
            'mark_kind' => $kind,
            'score' => $score,
            'last_edited_by' => $teacher->id,
        ]);
    };

    $mark($firstAssessment, $students[1], 'absent');
    $mark($firstAssessment, $students[2], 'empty');
    $mark($firstAssessment, $students[3], 'empty');
    $mark($secondAssessment, $students[0], 'score', 5);
    $mark($secondAssessment, $students[1], 'score', 9);
    $mark($secondAssessment, $students[2], 'score', 5);
    $mark($secondAssessment, $students[3], 'empty');

    Result::create([
        'institution_id' => $institution->id,
        'assessment_id' => $firstAssessment->id,
        'student_id' => $students[3]->id,
        'total' => 2,
        'max' => 10,
        'level' => 'BE',
    ]);
    Result::create([
        'institution_id' => $institution->id,
        'assessment_id' => $secondAssessment->id,
        'student_id' => $students[3]->id,
        'total' => 3,
        'max' => 10,
        'level' => 'BE',
    ]);

    $filters = new AnalyticsFilters($institution->id, 'West', $subject->id, 2026);
    $analytics = app(AnalyticsQueryService::class);

    expect(round($analytics->passRate($filters), 2))->toBe(66.67)
        ->and(round($analytics->meanScore($filters), 2))->toBe(53.33)
        ->and($analytics->scoreSpread($filters))->toBe([
            'min' => 20.0,
            'max' => 90.0,
            'iqr' => 37.5,
        ])
        ->and($analytics->entryCompleteness($filters))->toBe(87.5)
        ->and($analytics->performanceLevels($filters))->toBe([
            'EE' => 2,
            'ME' => 0,
            'AE' => 2,
            'BE' => 2,
        ])
        ->and($analytics->histogram($filters))->toBe([
            ['bin' => '0-9', 'count' => 0],
            ['bin' => '10-19', 'count' => 0],
            ['bin' => '20-29', 'count' => 1],
            ['bin' => '30-39', 'count' => 1],
            ['bin' => '40-49', 'count' => 0],
            ['bin' => '50-59', 'count' => 2],
            ['bin' => '60-69', 'count' => 0],
            ['bin' => '70-79', 'count' => 0],
            ['bin' => '80-89', 'count' => 1],
            ['bin' => '90-100', 'count' => 1],
        ])
        ->and($analytics->trend($filters))->toBe([
            [
                'key' => 'Maths|CAT 1|1',
                'subject' => 'Maths',
                'label' => 'CAT 1, 1',
                'date' => '2026-02-01',
                'passRate' => 50.0,
                'meanScore' => 50.0,
            ],
            [
                'key' => 'Maths|CAT 2|1',
                'subject' => 'Maths',
                'label' => 'CAT 2, 1',
                'date' => '2026-03-01',
                'passRate' => 75.0,
                'meanScore' => 55.0,
            ],
        ])
        ->and($analytics->criterionBreakdown($filters))->toBe([
            ['name' => 'Accuracy', 'pct' => 67.5, 'n' => 4],
        ])
        ->and($analytics->attentionList($filters))->toBe([
            [
                'studentId' => $students[3]->id,
                'meanScore' => 25.0,
                'latestScore' => 30.0,
                'level' => 'BE',
                'scored' => 2,
            ],
        ])
        ->and($analytics->declineList($filters))->toBe([
            [
                'studentId' => $students[0]->id,
                'latestScore' => 50.0,
                'priorMean' => 80.0,
            ],
        ])
        ->and($analytics->netLevelMovement($filters))->toBe(-1);
});

test('analytics queries return null for empty KPI denominators and honor scope filters', function () {
    $graph = buildGraph();
    $filters = new AnalyticsFilters($graph['institution']->id, 'East', $graph['subject']->id, 2026);
    $analytics = app(AnalyticsQueryService::class);

    expect($analytics->passRate($filters))->toBeNull()
        ->and($analytics->meanScore($filters))->toBeNull()
        ->and($analytics->scoreSpread($filters))->toBeNull()
        ->and($analytics->entryCompleteness($filters))->toBeNull()
        ->and($analytics->netLevelMovement($filters))->toBe(0);
});