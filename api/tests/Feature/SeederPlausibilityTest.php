<?php

use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Facades\DB;

/* Ticket #42's own "Done when": migrate:fresh --seed produces data against
   which every KPI in docs/spec/analytics.md returns a plausible non-empty
   result. No analytics service exists yet (that's #56), so this is the
   ground truth checked directly against the seeded rows with the same SQL
   shape those KPIs will eventually run, one test, one seed run: the seeder
   takes real time (~thirty seconds, ~9,600 marks), so this asserts
   everything it needs in a single pass rather than reseeding per
   assertion. */
test('the seeded school is plausible against every KPI in the analytics spec', function () {
    $this->seed(SchoolSeeder::class);

    // Scored outcomes: one row per (student, assessment), matching how a
    // grid resolves to a percentage of that rubric's maximum.
    $scoredOutcomes = DB::table('marks')
        ->join('assessments', 'marks.assessment_id', '=', 'assessments.id')
        ->join('criteria', 'marks.criterion_id', '=', 'criteria.id')
        ->where('marks.mark_kind', 'score')
        ->selectRaw('marks.student_id, assessments.subject_id, assessments.date, sum(marks.score) as total, sum(criteria.max_score) as max')
        ->groupBy('marks.student_id', 'assessments.subject_id', 'assessments.id', 'assessments.date')
        ->get()
        ->map(fn ($row) => (object) [
            'studentId' => $row->student_id,
            'subjectId' => $row->subject_id,
            'date' => $row->date,
            'pct' => $row->total / $row->max * 100,
        ]);

    expect($scoredOutcomes)->not->toBeEmpty();

    // Pass rate: a sane band, not the degenerate 0% or 100% a broken
    // generator (everyone absent, or everyone maxing out) would produce.
    $passRate = $scoredOutcomes->filter(fn ($o) => $o->pct >= 50)->count() / $scoredOutcomes->count() * 100;
    expect($passRate)->toBeGreaterThan(40.0)->toBeLessThan(95.0);

    // Performance levels: all four bands populated (lib/grading.ts's 80/60/40).
    $levelFor = fn (float $pct) => match (true) {
        $pct >= 80 => 'EE',
        $pct >= 60 => 'ME',
        $pct >= 40 => 'AE',
        default => 'BE',
    };
    $levelCounts = $scoredOutcomes->countBy(fn ($o) => $levelFor($o->pct));
    foreach (['EE', 'ME', 'AE', 'BE'] as $level) {
        expect($levelCounts->get($level, 0))->toBeGreaterThan(0, "no scored outcome reached level {$level}");
    }

    // Histogram: score spread across ten-point bands, not clustered in one.
    $histogram = $scoredOutcomes->countBy(fn ($o) => min(9, (int) floor($o->pct / 10)));
    expect($histogram->count())->toBeGreaterThanOrEqual(7, 'scores should spread across at least seven of the ten histogram bands');

    // Entry completeness / absent minority: present, but a minority, per
    // the ticket's "Do not: seed uniformly random marks... a deliberate
    // minority of absent values."
    $totalMarks = DB::table('marks')->count();
    $absentMarks = DB::table('marks')->where('mark_kind', 'absent')->count();
    expect($absentMarks)->toBeGreaterThan(0);
    expect($absentMarks / $totalMarks)->toBeLessThan(0.15);

    // Attention list: students whose mean in a stream+subject scope falls
    // below the pass mark. Grouped by (student, subject), matching a real
    // scope, not pooled across every subject a student takes.
    $meansByScope = $scoredOutcomes->groupBy(fn ($o) => "{$o->studentId}:{$o->subjectId}")
        ->map(fn ($outcomes) => $outcomes->avg('pct'));
    expect($meansByScope->filter(fn ($mean) => $mean < 50)->count())->toBeGreaterThan(0);

    // Decline: docs/spec/analytics.md's exact rule, latest scored pct at
    // least ten points below the mean of that student's own preceding
    // scored pcts in the same (student, subject) scope, with at least two
    // scored outcomes to compare. Verified against what the generator
    // actually produced after rounding and per-criterion jitter, not
    // assumed from how the twelve planted students were constructed:
    // twelve were planted, so this only proves the signal survived.
    $declining = 0;
    $netUp = 0;
    $netDown = 0;

    foreach ($scoredOutcomes->groupBy(fn ($o) => "{$o->studentId}:{$o->subjectId}") as $series) {
        $ordered = $series->sortBy('date')->values();
        $pcts = $ordered->pluck('pct');

        if ($pcts->count() >= 2) {
            $priorMean = $pcts->slice(0, -1)->avg();
            if ($pcts->last() <= $priorMean - 10) {
                $declining++;
            }
        }

        for ($i = 1; $i < $pcts->count(); $i++) {
            $prevLevel = $levelFor($pcts[$i - 1]);
            $currentLevel = $levelFor($pcts[$i]);
            $rank = fn (string $level) => array_flip(['BE', 'AE', 'ME', 'EE'])[$level];

            if ($rank($currentLevel) > $rank($prevLevel)) {
                $netUp++;
            } elseif ($rank($currentLevel) < $rank($prevLevel)) {
                $netDown++;
            }
        }
    }

    expect($declining)->toBeGreaterThanOrEqual(10, 'at least the twelve planted declining students should still trip the rule');
    expect($netUp)->toBeGreaterThan(0);
    expect($netDown)->toBeGreaterThan(0);

    // Assessments in scope never include a scheduled one: the final
    // sitting per class and subject is deliberately left scheduled and
    // unmarked, exercising that exclusion with real data rather than an
    // assumption about it.
    $scheduled = DB::table('assessments')->where('status', 'scheduled')->get();
    expect($scheduled)->not->toBeEmpty();
    foreach ($scheduled as $assessment) {
        expect(DB::table('marks')->where('assessment_id', $assessment->id)->exists())->toBeFalse();
    }

    // Dates strictly increasing per (class, subject): no two assessments
    // in the same scope share a date, so "latest" is never ambiguous.
    $duplicateDates = DB::table('assessments')
        ->select('class_id', 'subject_id', 'date', DB::raw('count(*) as c'))
        ->groupBy('class_id', 'subject_id', 'date')
        ->havingRaw('count(*) > 1')
        ->get();
    expect($duplicateDates)->toBeEmpty();
});
