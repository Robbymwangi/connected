<?php

use App\Models\Assessment;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Support\AnalyticsFilters;
use App\Support\AnalyticsQueryService;
use App\Support\Assistant\Handles;
use App\Support\Assistant\ReportTools;

/* ADR 0013 decision 7: the tools that answer broader questions. Every criterion is out of 10 and
   each subject has one, so a score of 7 is 70 percent and every figure below can be checked by
   hand. West is the report's stream; East is its sister in grade 4; North is grade 5 and must
   never appear.

   West, Maths      Term 1 CAT 1   Term 2 CAT 2      West, English   Term 2 CAT 1
   S. Student (F)        80             90                                 70
   Amina (F)             40             30                                 40
   Baraka (M)            50             70                                 80
   Chege (M)          absent            50                              absent

   East, Maths Term 2 CAT 2: two students at 60 and 80. North, Maths Term 2 CAT 2: one at 10. */
function toolsSchool(): array
{
    $g = buildGraph('Green Valley', '-tools');
    $g['assessment']->update(['status' => 'finalized']);
    $i = $g['institution']->id;
    $english = Subject::create(['institution_id' => $i, 'name' => 'English']);
    $comprehension = Criterion::create(['institution_id' => $i, 'subject_id' => $english->id, 'name' => 'Comprehension', 'max_score' => 10]);
    $east = SchoolClass::create(['institution_id' => $i, 'grade' => '4', 'stream' => 'East']);
    $north = SchoolClass::create(['institution_id' => $i, 'grade' => '5', 'stream' => 'North']);

    $student = function (string $name, string $gender, SchoolClass $class) use ($i): Student {
        $student = Student::create(['institution_id' => $i, 'name' => $name, 'gender' => $gender, 'dob' => '2016-01-01']);
        Enrolment::create(['institution_id' => $i, 'student_id' => $student->id, 'class_id' => $class->id, 'year' => 2026]);

        return $student;
    };
    $assessment = fn (SchoolClass $class, Subject $subject, string $name, int $term, string $date) => Assessment::create([
        'institution_id' => $i, 'class_id' => $class->id, 'subject_id' => $subject->id, 'name' => $name,
        'term' => $term, 'year' => 2026, 'date' => $date, 'status' => 'finalized', 'created_by' => $g['teacher']->id,
    ]);
    $mark = fn (Assessment $assessment, Student $student, Criterion $criterion, ?int $score) => Mark::create([
        'institution_id' => $i, 'assessment_id' => $assessment->id, 'student_id' => $student->id, 'criterion_id' => $criterion->id,
        'mark_kind' => $score === null ? 'absent' : 'score', 'score' => $score, 'last_edited_by' => $g['teacher']->id,
    ]);

    $s = ['student' => $g['student'], 'amina' => $student('Amina', 'F', $g['class']), 'baraka' => $student('Baraka', 'M', $g['class']), 'chege' => $student('Chege', 'M', $g['class'])];
    $maths1 = $g['assessment'];
    $maths2 = $assessment($g['class'], $g['subject'], 'CAT 2', 2, '2026-06-01');
    $english1 = $assessment($g['class'], $english, 'CAT 1', 2, '2026-06-02');
    $accuracy = $g['criterion'];

    foreach ([[$maths1, $accuracy, [8, 4, 5, null]], [$maths2, $accuracy, [9, 3, 7, 5]], [$english1, $comprehension, [7, 4, 8, null]]] as [$sitting, $criterion, $scores]) {
        foreach (array_values($s) as $index => $who) {
            // The graph already holds S. Student's 8 on Maths CAT 1, and a mark's id is derived from
            // its assessment, student, and criterion.
            if ($sitting->is($maths1) && $who->is($g['student'])) {
                continue;
            }
            $mark($sitting, $who, $criterion, $scores[$index]);
        }
    }

    $eastMaths = $assessment($east, $g['subject'], 'CAT 2', 2, '2026-06-01');
    $mark($eastMaths, $student('Esther', 'F', $east), $accuracy, 6);
    $mark($eastMaths, $student('Erick', 'M', $east), $accuracy, 8);
    $mark($assessment($north, $g['subject'], 'CAT 2', 2, '2026-06-01'), $student('Njoro', 'M', $north), $accuracy, 1);

    return [...$g, 'students' => $s];
}

function toolsFor(array $school, ?string $subjectId, ?int $term, Handles $handles = new Handles): ReportTools
{
    return new ReportTools(app(AnalyticsQueryService::class), new AnalyticsFilters($school['institution']->id, 'West', $subjectId, 2026, $term), $handles);
}

test('class standings rank the whole class by mean and mark its thirds (positions under a third of the class, here 4/3, are the top)', function () {
    $school = toolsSchool();
    $handles = new Handles;
    $result = toolsFor($school, $school['subject']->id, 2, $handles)->run('class_standings', []);

    expect(array_map(fn (array $row) => [$handles->studentFor($row['student']), $row['meanPct'], $row['third']], $result['students']))->toBe([
        [$school['students']['student']->id, 90.0, 'top'],
        [$school['students']['baraka']->id, 70.0, 'top'],
        [$school['students']['chege']->id, 50.0, 'middle'],
        [$school['students']['amina']->id, 30.0, 'bottom'],
    ]);
});

test('improvers are the mirror of the decline list, largest rise first', function () {
    $school = toolsSchool();
    $handles = new Handles;
    $result = toolsFor($school, $school['subject']->id, null, $handles)->run('improvers', []);

    expect(array_map(fn (array $row) => [$handles->studentFor($row['student']), $row['latestPct'], $row['earlierMeanPct'], $row['risePct']], $result['students']))->toBe([
        [$school['students']['baraka']->id, 70.0, 50.0, 20.0],
        [$school['students']['student']->id, 90.0, 80.0, 10.0],
    ]);
});

test('absences and gaps count each assessment and name repeat absentees by handle', function () {
    $school = toolsSchool();
    $handles = new Handles;
    $result = toolsFor($school, null, null, $handles)->run('absences_and_gaps', []);

    expect(array_map(fn (array $row) => [$row['subject'], $row['assessment'], $row['expected'], $row['scored'], $row['absent'], $row['missing']], $result['assessments']))->toBe([
        ['Maths', 'CAT 1', 4, 3, 1, 0],
        ['Maths', 'CAT 2', 4, 4, 0, 0],
        ['English', 'CAT 1', 4, 3, 1, 0],
    ]);
    expect($result['absentMoreThanOnce'])->toHaveCount(1);
    expect($handles->studentFor($result['absentMoreThanOnce'][0]['student']))->toBe($school['students']['chege']->id);
    expect($result['absentMoreThanOnce'][0]['absences'])->toBe(2);
});

test('one student across subjects sits beside the stream mean in each, with the gap', function () {
    $school = toolsSchool();
    $handles = new Handles;
    $baraka = $handles->for($school['students']['baraka']->id);
    $result = toolsFor($school, $school['subject']->id, 2, $handles)->run('student_across_subjects', ['student' => $baraka]);

    expect($result['subjects'])->toBe([
        ['subject' => 'English', 'studentMeanPct' => 80.0, 'assessmentsScored' => 1, 'streamMeanPct' => 63.3, 'gapFromStreamPct' => 16.7, 'belowPassMark' => false],
        ['subject' => 'Maths', 'studentMeanPct' => 70.0, 'assessmentsScored' => 1, 'streamMeanPct' => 60.0, 'gapFromStreamPct' => 10.0, 'belowPassMark' => false],
    ]);
    expect(toolsFor($school, null, 2)->run('student_across_subjects', ['student' => 'S9']))->toHaveKey('error');
});

test('students below the pass mark in two or more subjects are found, and one weak subject is not enough', function () {
    $school = toolsSchool();
    $handles = new Handles;
    $result = toolsFor($school, $school['subject']->id, 2, $handles)->run('struggling_in_several_subjects', []);

    expect($result['students'])->toHaveCount(1);
    expect($handles->studentFor($result['students'][0]['student']))->toBe($school['students']['amina']->id);
    expect($result['students'][0]['subjects'])->toBe([['subject' => 'English', 'meanPct' => 40.0], ['subject' => 'Maths', 'meanPct' => 30.0]]);
});

test('the subject breakdown ranks the stream\'s subjects and gives each its gap from the stream mean', function () {
    $school = toolsSchool();
    $result = toolsFor($school, $school['subject']->id, 2)->run('subject_breakdown', []);

    expect($result['streamMeanPct'])->toBe(61.4);
    expect(array_map(fn (array $row) => [$row['subject'], $row['meanPct'], $row['passRatePct'], $row['gapFromStreamMeanPct']], $result['subjects']))->toBe([
        ['English', 63.3, 66.7, 1.9],
        ['Maths', 60.0, 75.0, -1.4],
    ]);
});

test('term deviation compares with the term before in the same year, and term 1 has none', function () {
    $school = toolsSchool();

    expect(toolsFor($school, $school['subject']->id, 2)->run('term_deviation', []))->toBe([
        'term' => 2, 'comparedWithTerm' => 1, 'subjects' => [[
            'subject' => 'Maths', 'meanPct' => 60.0, 'earlierMeanPct' => 56.7, 'meanDeviationPct' => 3.3,
            'passRatePct' => 75.0, 'earlierPassRatePct' => 66.7, 'passRateDeviationPct' => 8.3,
        ]],
    ]);
    expect(toolsFor($school, $school['subject']->id, null)->run('term_deviation', [])['term'])->toBe(2);
    expect(toolsFor($school, $school['subject']->id, 1)->run('term_deviation', []))->toHaveKey('note');
});

test('the stream comparison reaches only the same grade and works out every gap', function () {
    $school = toolsSchool();
    $result = toolsFor($school, $school['subject']->id, 2)->run('stream_comparison', []);

    expect($result['sameAssessments'])->toBeTrue();
    expect($result['gradeMeanPct'])->toBe(63.3);
    expect(array_map(fn (array $row) => [$row['stream'], $row['meanPct'], $row['passRatePct'], $row['scoredEntries'], $row['gapFromGradeMeanPct'], $row['meanDifferenceFromThisStreamPct'], $row['passRateDifferenceFromThisStreamPct']], $result['streams']))->toBe([
        ['West', 60.0, 75.0, 4, -3.3, 0.0, 0.0],
        ['East', 70.0, 100.0, 2, 6.7, 10.0, 25.0],
    ]);
    expect(json_encode($result))->not->toContain('North');
});

test('a report on one assessment holds every stream to the assessment of that name', function () {
    $school = toolsSchool();
    $cat2 = Assessment::query()->where('class_id', $school['class']->id)->where('name', 'CAT 2')->value('id');
    $tools = new ReportTools(app(AnalyticsQueryService::class), new AnalyticsFilters($school['institution']->id, 'West', $school['subject']->id, 2026, null, null, $cat2), new Handles);
    $result = $tools->run('stream_comparison', []);

    expect($result['comparedOn'])->toBe('CAT 2');
    expect($result['sameAssessments'])->toBeTrue();
    expect(array_map(fn (array $row) => [$row['stream'], $row['meanPct'], $row['scoredEntries']], $result['streams']))->toBe([
        ['West', 60.0, 4],
        ['East', 70.0, 2],
    ]);
});

test('the criterion comparison sets this stream beside its sisters, and needs one subject', function () {
    $school = toolsSchool();

    expect(toolsFor($school, $school['subject']->id, 2)->run('criterion_across_streams', [])['criteria'])->toBe([[
        'criterion' => 'Accuracy', 'streams' => [
            ['stream' => 'West', 'meanPct' => 60.0, 'isThisStream' => true, 'differenceFromThisStreamPct' => 0.0],
            ['stream' => 'East', 'meanPct' => 70.0, 'isThisStream' => false, 'differenceFromThisStreamPct' => 10.0],
        ],
    ]]);
    expect(toolsFor($school, null, 2)->run('criterion_across_streams', []))->toHaveKey('error');
});

test('a gender group of fewer than five students is withheld, and so is the gap', function () {
    $school = toolsSchool();
    $result = toolsFor($school, $school['subject']->id, 2)->run('gender_breakdown', []);

    expect($result['girls'])->toHaveKey('withheld');
    expect($result['boys'])->toHaveKey('withheld');
    expect($result['girlsMinusBoysMeanPct'])->toBeNull();
});

test('two groups of five or more get their totals and the gap between them', function () {
    $g = buildGraph('Big School', '-gender');
    $g['assessment']->update(['status' => 'finalized']);
    Mark::query()->whereKey($g['mark']->id)->update(['score' => 6]);
    foreach ([['F', 6], ['F', 6], ['F', 6], ['F', 6], ['M', 4], ['M', 4], ['M', 4], ['M', 4], ['M', 4]] as $n => [$gender, $score]) {
        $student = Student::create(['institution_id' => $g['institution']->id, 'name' => "Pupil {$n}", 'gender' => $gender, 'dob' => '2016-01-01']);
        Enrolment::create(['institution_id' => $g['institution']->id, 'student_id' => $student->id, 'class_id' => $g['class']->id, 'year' => 2026]);
        Mark::create(['institution_id' => $g['institution']->id, 'assessment_id' => $g['assessment']->id, 'student_id' => $student->id, 'criterion_id' => $g['criterion']->id, 'mark_kind' => 'score', 'score' => $score, 'last_edited_by' => $g['teacher']->id]);
    }

    $result = toolsFor($g, $g['subject']->id, 1)->run('gender_breakdown', []);

    expect($result)->toBe([
        'girls' => ['students' => 5, 'meanPct' => 60.0, 'passRatePct' => 100.0, 'resultsPerLevel' => ['EE' => 0, 'ME' => 5, 'AE' => 0, 'BE' => 0]],
        'boys' => ['students' => 5, 'meanPct' => 40.0, 'passRatePct' => 0.0, 'resultsPerLevel' => ['EE' => 0, 'ME' => 0, 'AE' => 5, 'BE' => 0]],
        'girlsMinusBoysMeanPct' => 20.0,
        'girlsMinusBoysPassRatePct' => 100.0,
    ]);
});

test('no tool result carries a student name, a teacher, or another grade', function () {
    $school = toolsSchool();
    $handles = new Handles;
    $tools = toolsFor($school, $school['subject']->id, 2, $handles);
    $everything = json_encode(array_map(
        fn (array $tool) => $tools->run($tool['name'], ['student' => $handles->for($school['students']['amina']->id)]),
        ReportTools::definitions(),
    ));

    foreach (['S. Student', 'Amina', 'Baraka', 'Chege', 'Esther', 'Erick', 'Njoro', 'T. Teacher', 'North', 'Green Valley'] as $leak) {
        expect($everything)->not->toContain($leak);
    }
});
