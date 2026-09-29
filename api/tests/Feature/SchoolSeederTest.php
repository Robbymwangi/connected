<?php

use App\Models\Mark;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Facades\DB;

/* Ticket #42: the seeded school is what every analytics figure is tested
   against, so these assertions are the proof that "every KPI returns a
   plausible non-empty result" (docs/build-plan.md 1.4) while 4.1's query
   service does not exist yet. Each figure is recomputed here in SQL, straight
   from the seeded rows, following docs/spec/analytics.md. */

/* One row per student per finalized assessment with every criterion scored:
   the "scored" outcome of analytics.md, as a percentage of the rubric max. */
const SCORED_OUTCOMES = "
    select m.student_id, a.id as assessment_id, a.subject_id, a.class_id, a.date,
           sum(m.score) * 100.0 / sum(c.max_score) as pct
    from marks m
    join assessments a on a.id = m.assessment_id
    join criteria c on c.id = m.criterion_id
    where a.status = 'finalized' and m.mark_kind = 'score'
    group by m.student_id, a.id, a.subject_id, a.class_id, a.date
";

test('the seeded school has the right shape, integrity, and absence handling', function () {
    $this->seed(SchoolSeeder::class);

    // the school has the shape the ticket asks for
    expect(DB::table('institutions')->count())->toBe(1);
    expect(DB::table('classes')->distinct()->count('grade'))->toBe(2);
    expect(DB::table('classes')->count())->toBe(4);
    expect(DB::table('subjects')->count())->toBe(6);
    expect(DB::table('students')->count())->toBe(80);
    expect(DB::table('enrolments')->count())->toBe(80);
    expect(DB::table('class_subjects')->count())->toBe(24);
    expect(DB::table('teacher_assignments')->count())->toBe(24);
    expect(DB::table('users')->where('is_admin', true)->count())->toBe(1);
    expect(DB::table('subject_moderations')->count())->toBeGreaterThanOrEqual(1);
    expect(DB::table('assessments')->distinct()->pluck('term')->sort()->values()->all())->toBe([1, 2, 3]);
    expect(DB::table('criteria')->where('max_score', '<=', 0)->count())->toBe(0);

    // every row is tenant-stamped, at version 0, and live
    $institutionId = DB::table('institutions')->value('id');

    foreach (['users', 'subjects', 'criteria', 'classes', 'class_subjects', 'teacher_assignments',
        'subject_moderations', 'students', 'enrolments', 'assessments', 'marks', 'results'] as $table) {
        expect(DB::table($table)->where('institution_id', '!=', $institutionId)->count())->toBe(0, $table);
        expect(DB::table($table)->whereNotNull('deleted_at')->count())->toBe(0, $table);
    }

    expect(DB::table('marks')->where('version', '!=', 0)->count())->toBe(0);

    // mark ids are the deterministic UUIDv5 of their cell, and no score exceeds its criterion max
    foreach (DB::table('marks')->inRandomOrder()->limit(300)->get() as $row) {
        $expected = (new Mark)->forceFill([
            'assessment_id' => $row->assessment_id,
            'student_id' => $row->student_id,
            'criterion_id' => $row->criterion_id,
        ])->newUniqueId();

        expect($row->id)->toBe($expected);
    }

    $overMax = DB::selectOne('
        select count(*) as n from marks m join criteria c on c.id = m.criterion_id
        where m.score > c.max_score
    ')->n;

    expect($overMax)->toBe(0);

    // absence is an explicit mark_kind on a deliberate minority of rows, never a null score
    $rows = DB::selectOne("
        select count(distinct (assessment_id, student_id)) as total,
               count(distinct (assessment_id, student_id)) filter (where mark_kind = 'absent') as absent
        from marks
    ");

    $share = $rows->absent / $rows->total;
    expect($share)->toBeGreaterThan(0.01)->toBeLessThan(0.06);

    // A row is wholly absent or wholly scored, except the deliberately
    // half-entered one, which only ever holds scores.
    $mixed = DB::selectOne("
        select count(*) as n from (
            select assessment_id, student_id from marks
            group by assessment_id, student_id
            having count(*) filter (where mark_kind = 'absent') > 0
               and count(*) filter (where mark_kind = 'score') > 0
        ) t
    ")->n;

    expect($mixed)->toBe(0);
});

test('the seeded school gives every analytics figure something plausible to report', function () {
    $this->seed(SchoolSeeder::class);

    // the KPI strip has something to say: a pass rate strictly between 0 and 1, and all four levels
    $stats = DB::selectOne('
        with o as ('.SCORED_OUTCOMES.')
        select count(*) as scored,
               count(*) filter (where pct >= 50) * 1.0 / count(*) as pass_rate,
               avg(pct) as mean, min(pct) as lo, max(pct) as hi
        from o
    ');

    expect($stats->scored)->toBeGreaterThan(1500);
    expect((float) $stats->pass_rate)->toBeGreaterThan(0.5)->toBeLessThan(0.95);
    expect((float) $stats->mean)->toBeGreaterThan(45)->toBeLessThan(75);
    expect((float) $stats->hi - (float) $stats->lo)->toBeGreaterThan(50);

    expect(DB::table('results')->distinct()->pluck('level')->sort()->values()->all())
        ->toBe(['AE', 'BE', 'EE', 'ME']);

    // the histogram spreads across many bands and the attention list is not empty
    $bands = DB::select('
        with o as ('.SCORED_OUTCOMES.')
        select least(floor(pct / 10), 9) as band, count(*) as n from o group by 1
    ');

    expect(count($bands))->toBeGreaterThanOrEqual(6);

    $attention = DB::selectOne('
        with o as ('.SCORED_OUTCOMES.')
        select count(*) as n from (select student_id, avg(pct) as mean from o group by student_id) s
        where mean < 50
    ')->n;

    expect($attention)->toBeGreaterThanOrEqual(6);

    // entry completeness has a gap: a part-entered assessment and a half-entered row
    $criteriaPerSubject = DB::table('criteria')->select('subject_id', DB::raw('count(*) as n'))->groupBy('subject_id')->pluck('n', 'subject_id');

    $gaps = 0;
    foreach (DB::table('assessments')->where('status', 'scheduled')->get() as $assessment) {
        $entered = DB::table('marks')->where('assessment_id', $assessment->id)->count();
        if ($entered > 0 && $entered < 20 * $criteriaPerSubject[$assessment->subject_id]) {
            $gaps++;
        }
    }

    // One part-entered Term 3 CAT 2 per stream and subject.
    expect($gaps)->toBe(24);

    $halfEntered = DB::selectOne('
        select count(*) as n from (
            select m.assessment_id, m.student_id from marks m
            join assessments a on a.id = m.assessment_id
            join criteria c on c.subject_id = a.subject_id
            group by m.assessment_id, m.student_id, a.subject_id
            having count(distinct m.criterion_id) < count(distinct c.id)
        ) t
    ')->n;

    expect($halfEntered)->toBeGreaterThanOrEqual(1);
    expect(DB::table('assessments')->where('status', 'scheduled')->whereNotExists(
        fn ($q) => $q->select(DB::raw(1))->from('marks')->whereColumn('marks.assessment_id', 'assessments.id')
    )->count())->toBe(24);

    // each result is the sum of its marks against the sum of the rubric, at the right level
    $mismatch = DB::selectOne("
        select count(*) as n from results r
        join (
            select m.assessment_id, m.student_id, sum(m.score) as total, sum(c.max_score) as max
            from marks m join criteria c on c.id = m.criterion_id
            where m.mark_kind = 'score'
            group by m.assessment_id, m.student_id
        ) s on s.assessment_id = r.assessment_id and s.student_id = r.student_id
        where s.total != r.total or s.max != r.max
           or r.level != case when r.total * 1.0 / r.max >= 0.8 then 'EE'
                              when r.total * 1.0 / r.max >= 0.6 then 'ME'
                              when r.total * 1.0 / r.max >= 0.4 then 'AE' else 'BE' end
    ")->n;

    expect($mismatch)->toBe(0);

    // A result exists exactly for the finalized, wholly scored rows.
    $scoredRows = DB::selectOne('with o as ('.SCORED_OUTCOMES.') select count(*) as n from o')->n;
    expect(DB::table('results')->count())->toBe($scoredRows);

    // the decline flag has students to find, in a subject scope and in Overall
    // analytics.md, Decline and attention: latest scored percentage at least
    // ten points below the mean of the student's own earlier scored
    // percentages in the same scope, with at least two outcomes.
    $flagged = fn (string $partition) => DB::selectOne('
        with o as ('.SCORED_OUTCOMES.'),
        w as (
            select student_id, pct,
                   avg(pct) over (partition by '.$partition.' order by date, assessment_id rows between unbounded preceding and 1 preceding) as prior_mean,
                   count(*) over (partition by '.$partition.' order by date, assessment_id rows between unbounded preceding and 1 preceding) as prior_n,
                   row_number() over (partition by '.$partition.' order by date desc, assessment_id desc) as recency
            from o
        )
        select count(distinct student_id) as n from w
        where recency = 1 and prior_n >= 1 and pct <= prior_mean - 10
    ')->n;

    expect($flagged('student_id, subject_id'))->toBeGreaterThanOrEqual(6);
    expect($flagged('student_id'))->toBeGreaterThanOrEqual(6);
});
