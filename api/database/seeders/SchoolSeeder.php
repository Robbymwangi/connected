<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Institution;
use App\Models\Mark;
use App\Models\Result;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\TeacherAssignment;
use App\Models\User;
use Database\Seeders\Support\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/* Ticket #42, "the highest-leverage one in the plan": one realistic school,
   seeded so every KPI in docs/spec/analytics.md has a plausible non-empty
   answer to compute against, and so #56's analytics work has real ground
   truth rather than a fresh guess every run. Everything here uses
   SeededRandom, not mt_rand or Faker's own generator, for exactly that
   reason: a non-deterministic seeder would change who's declining and what
   the pass rate is on every run.

   Scores are planted at the assessment level (a target percentage per
   student per sitting), not assembled bottom-up from independently random
   criterion scores: decline, improvement, and the absent minority all need
   to survive rounding and per-criterion jitter, which SeederPlausibilityTest
   verifies actually happened rather than assuming it from how this class is
   written. */
class SchoolSeeder extends Seeder
{
    private SeededRandom $random;

    private const SITTING_DATES = ['2026-02-16', '2026-04-13', '2026-06-15', '2026-08-17', '2026-09-14', '2026-11-16'];

    private const SITTING_NAMES = ['CAT 1', 'End of Term', 'CAT 1', 'End of Term', 'CAT 1', 'End of Term'];

    private const SITTING_TERMS = [1, 1, 2, 2, 3, 3];

    /* The last sitting is deliberately never marked, regardless of when
       this seeder actually runs: hardcoded dates compared against whoever's
       real "now" would make the seeded data's own shape depend on run
       date, which is exactly the non-determinism SeededRandom exists to
       avoid elsewhere. It's a scheduled assessment by construction, not by
       being in the future. docs/spec/analytics.md's scope filter excludes
       it ("never a scheduled assessment"), which this exercises for real. */
    private const LAST_SITTING_INDEX = 5;

    private const ABSENT_PROBABILITY = 0.04;

    private const MALE_FIRST_NAMES = ['Brian', 'Kevin', 'Dennis', 'Collins', 'Felix', 'Victor', 'Samuel', 'Peter', 'James', 'Josphat', 'Emmanuel', 'Brian', 'Hillary', 'Elvis', 'Erick', 'Duncan', 'Allan', 'Moses', 'Stephen', 'Martin'];

    private const FEMALE_FIRST_NAMES = ['Faith', 'Mercy', 'Joy', 'Grace', 'Purity', 'Winnie', 'Brenda', 'Sharon', 'Lilian', 'Esther', 'Ann', 'Caroline', 'Diana', 'Eunice', 'Beatrice', 'Christine', 'Doreen', 'Millicent', 'Naomi', 'Rael'];

    private const SURNAMES = ['Mwangi', 'Otieno', 'Wanjiru', 'Kiptoo', 'Achieng', 'Njoroge', 'Kamau', 'Wafula', 'Cherono', 'Mutua', 'Kariuki', 'Onyango', 'Chebet', 'Waweru', 'Ochieng', 'Nyambura', 'Kiplagat', 'Odhiambo', 'Muthoni', 'Barasa'];

    public function run(): void
    {
        $this->random = new SeededRandom;

        DB::transaction(function () {
            $institution = Institution::create(['name' => 'Green Valley Primary School']);

            [$admin, $classTeachers] = $this->seedStaff($institution);
            $subjects = $this->seedSubjectsAndCriteria($institution);
            $classes = $this->seedClasses($institution, $classTeachers);

            $this->seedClassSubjects($institution, $classes, $subjects);
            $this->seedTeacherAssignments($institution, $classes, $subjects, $classTeachers);
            $this->seedSubjectModerations($institution, $admin, $subjects);

            $studentsByClass = $this->seedStudentsAndEnrolments($institution, $classes);

            $trends = $this->assignTrends($studentsByClass, $subjects);

            $this->seedAssessmentsMarksAndResults(
                $institution, $classes, $subjects, $studentsByClass, $classTeachers, $trends,
            );
        });
    }

    /**
     * @return array{0: User, 1: array<string, User>}
     */
    private function seedStaff(Institution $institution): array
    {
        $admin = User::create([
            'institution_id' => $institution->id,
            'name' => 'Grace Wanjiru',
            'email' => 'admin@greenvalley.test',
            'password' => 'password',
            'is_admin' => true,
        ]);

        $classTeachers = [
            '4W' => User::create(['institution_id' => $institution->id, 'name' => 'John Otieno', 'email' => 'j.otieno@greenvalley.test', 'password' => 'password']),
            '4E' => User::create(['institution_id' => $institution->id, 'name' => 'Mercy Achieng', 'email' => 'm.achieng@greenvalley.test', 'password' => 'password']),
            '5A' => User::create(['institution_id' => $institution->id, 'name' => 'Peter Kariuki', 'email' => 'p.kariuki@greenvalley.test', 'password' => 'password']),
            '5B' => User::create(['institution_id' => $institution->id, 'name' => 'Esther Chebet', 'email' => 'e.chebet@greenvalley.test', 'password' => 'password']),
        ];

        return [$admin, $classTeachers];
    }

    /**
     * @return array<string, array{subject: Subject, criteria: array<int, Criterion>}>
     */
    private function seedSubjectsAndCriteria(Institution $institution): array
    {
        $rubrics = [
            'English' => [['Comprehension', 20], ['Written Expression', 15], ['Oral Fluency', 15], ['Vocabulary', 10]],
            'Maths' => [['Number Operations', 20], ['Algebra', 20], ['Geometry', 15], ['Data Handling', 15], ['Problem Solving', 30]],
            'Science' => [['Knowledge', 25], ['Practical', 25], ['Analysis', 25], ['Communication', 25]],
            'Kiswahili' => [['Ufahamu', 20], ['Sarufi', 20], ['Insha', 20], ['Mazungumzo', 10]],
            'Social Studies' => [['Knowledge & Facts', 25], ['Map Work', 25], ['Citizenship', 25], ['Analysis', 25]],
            'CRE' => [['Knowledge', 30], ['Application', 30], ['Values & Ethics', 20]],
        ];

        $subjects = [];

        foreach ($rubrics as $name => $criteria) {
            $subject = Subject::create(['institution_id' => $institution->id, 'name' => $name]);

            $subjects[$name] = [
                'subject' => $subject,
                'criteria' => array_map(
                    fn (array $c) => Criterion::create([
                        'institution_id' => $institution->id,
                        'subject_id' => $subject->id,
                        'name' => $c[0],
                        'max_score' => $c[1],
                    ]),
                    $criteria,
                ),
            ];
        }

        return $subjects;
    }

    /**
     * @param  array<string, User>  $classTeachers
     * @return array<string, SchoolClass>
     */
    private function seedClasses(Institution $institution, array $classTeachers): array
    {
        $definitions = [
            '4W' => 'Grade 4', '4E' => 'Grade 4',
            '5A' => 'Grade 5', '5B' => 'Grade 5',
        ];

        $classes = [];

        foreach ($definitions as $stream => $grade) {
            $classes[$stream] = SchoolClass::create([
                'institution_id' => $institution->id,
                'grade' => $grade,
                'stream' => $stream,
                'class_teacher_id' => $classTeachers[$stream]->id,
            ]);
        }

        return $classes;
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, array{subject: Subject, criteria: array<int, Criterion>}>  $subjects
     */
    private function seedClassSubjects(Institution $institution, array $classes, array $subjects): void
    {
        foreach ($classes as $class) {
            foreach ($subjects as $entry) {
                ClassSubject::create([
                    'institution_id' => $institution->id,
                    'class_id' => $class->id,
                    'subject_id' => $entry['subject']->id,
                ]);
            }
        }
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, array{subject: Subject, criteria: array<int, Criterion>}>  $subjects
     * @param  array<string, User>  $classTeachers
     */
    private function seedTeacherAssignments(Institution $institution, array $classes, array $subjects, array $classTeachers): void
    {
        // Each class teacher covers every subject for their own class: the
        // ordinary primary-school pattern, and enough for #36's lifecycle
        // scoping (finalize, unlock) to have someone to scope to.
        foreach ($classes as $stream => $class) {
            foreach ($subjects as $entry) {
                TeacherAssignment::create([
                    'institution_id' => $institution->id,
                    'user_id' => $classTeachers[$stream]->id,
                    'class_id' => $class->id,
                    'subject_id' => $entry['subject']->id,
                ]);
            }
        }
    }

    /**
     * @param  array<string, array{subject: Subject, criteria: array<int, Criterion>}>  $subjects
     */
    private function seedSubjectModerations(Institution $institution, User $admin, array $subjects): void
    {
        foreach (['Maths', 'Science'] as $name) {
            SubjectModeration::create([
                'institution_id' => $institution->id,
                'user_id' => $admin->id,
                'subject_id' => $subjects[$name]['subject']->id,
            ]);
        }
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @return array<string, array<int, Student>>
     */
    private function seedStudentsAndEnrolments(Institution $institution, array $classes): array
    {
        $studentsByClass = [];

        foreach ($classes as $stream => $class) {
            $grade = $class->grade;
            // Grade 4 pupils are roughly 9 turning 10 in 2026; Grade 5,
            // roughly 10 turning 11. A birth-year range, not a fixed age,
            // for natural variation.
            [$minYear, $maxYear] = $grade === 'Grade 4' ? [2016, 2017] : [2015, 2016];

            $studentsByClass[$stream] = [];

            for ($i = 0; $i < 20; $i++) {
                $isMale = $this->random->bool(0.5);
                $firstName = $this->random->pick($isMale ? self::MALE_FIRST_NAMES : self::FEMALE_FIRST_NAMES);
                $surname = $this->random->pick(self::SURNAMES);

                $dob = sprintf(
                    '%d-%02d-%02d',
                    $this->random->int($minYear, $maxYear),
                    $this->random->int(1, 12),
                    $this->random->int(1, 28),
                );

                $student = Student::create([
                    'institution_id' => $institution->id,
                    'name' => "{$firstName} {$surname}",
                    'gender' => $isMale ? 'M' : 'F',
                    'dob' => $dob,
                ]);

                Enrolment::create([
                    'institution_id' => $institution->id,
                    'student_id' => $student->id,
                    'class_id' => $class->id,
                    'year' => 2026,
                ]);

                $studentsByClass[$stream][] = $student;
            }
        }

        return $studentsByClass;
    }

    /**
     * Picks twelve (student, subject) pairs to decline and twelve to
     * improve, spread across every class so no single scope carries all of
     * the signal. Planted as an explicit five-point percentage sequence,
     * not derived from a trend slope, so the final gap against the
     * preceding mean is guaranteed rather than merely likely:
     * docs/spec/analytics.md's decline rule needs "at least ten points
     * below the mean of their own preceding scored percentages," and a
     * slope-based approach would need its own verification that every
     * planted student actually clears that bar.
     *
     * @param  array<string, array<int, Student>>  $studentsByClass
     * @param  array<string, array{subject: Subject, criteria: array<int, Criterion>}>  $subjects
     * @return array{declining: array<string, array<int, float>>, improving: array<string, array<int, float>>}
     */
    private function assignTrends(array $studentsByClass, array $subjects): array
    {
        $decliningSequence = [78.0, 74.0, 69.0, 62.0, 43.0];
        $improvingSequence = [42.0, 50.0, 58.0, 68.0, 79.0];

        $declining = [];
        $improving = [];
        $subjectNames = array_keys($subjects);

        foreach ($studentsByClass as $students) {
            $shuffled = $this->random->shuffled($students);

            foreach (array_slice($shuffled, 0, 3) as $student) {
                $declining["{$student->id}:".$this->random->pick($subjectNames)] = $decliningSequence;
            }

            foreach (array_slice($shuffled, 3, 3) as $student) {
                $improving["{$student->id}:".$this->random->pick($subjectNames)] = $improvingSequence;
            }
        }

        return ['declining' => $declining, 'improving' => $improving];
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, array{subject: Subject, criteria: array<int, Criterion>}>  $subjects
     * @param  array<string, array<int, Student>>  $studentsByClass
     * @param  array<string, User>  $classTeachers
     * @param  array{declining: array<string, array<int, float>>, improving: array<string, array<int, float>>}  $trends
     */
    private function seedAssessmentsMarksAndResults(
        Institution $institution,
        array $classes,
        array $subjects,
        array $studentsByClass,
        array $classTeachers,
        array $trends,
    ): void {
        foreach ($classes as $stream => $class) {
            $teacher = $classTeachers[$stream];
            $students = $studentsByClass[$stream];

            // A stable per-student baseline ability for every subject, so
            // an unplanted student's scores stay centered on one value
            // across all six sittings rather than drifting: mean 64,
            // spread wide enough that all four performance levels (EE 80+,
            // ME 60-79, AE 40-59, BE under 40) end up populated somewhere
            // across ~20 students x 6 subjects.
            $baselines = [];
            foreach ($students as $student) {
                foreach ($subjects as $subjectName => $entry) {
                    $baselines["{$student->id}:{$subjectName}"] = max(12, min(98, $this->random->normal(64, 17)));
                }
            }

            foreach ($subjects as $subjectName => $entry) {
                $subject = $entry['subject'];
                $criteria = $entry['criteria'];
                $maxTotal = array_sum(array_map(fn (Criterion $c) => $c->max_score, $criteria));

                for ($sitting = 0; $sitting < 6; $sitting++) {
                    $assessment = Assessment::create([
                        'institution_id' => $institution->id,
                        'class_id' => $class->id,
                        'subject_id' => $subject->id,
                        'name' => self::SITTING_NAMES[$sitting],
                        'term' => self::SITTING_TERMS[$sitting],
                        'year' => 2026,
                        'date' => self::SITTING_DATES[$sitting],
                        'status' => $sitting === self::LAST_SITTING_INDEX ? 'scheduled' : 'finalized',
                        'created_by' => $teacher->id,
                        'finalized_at' => $sitting === self::LAST_SITTING_INDEX ? null : self::SITTING_DATES[$sitting].' 15:00:00',
                        'finalized_by' => $sitting === self::LAST_SITTING_INDEX ? null : $teacher->id,
                    ]);

                    if ($sitting === self::LAST_SITTING_INDEX) {
                        continue;
                    }

                    foreach ($students as $student) {
                        $key = "{$student->id}:{$subjectName}";
                        $isProtected = isset($trends['declining'][$key]) || isset($trends['improving'][$key]);

                        $isAbsent = ! ($isProtected && $sitting === self::LAST_SITTING_INDEX - 1)
                            && $this->random->bool(self::ABSENT_PROBABILITY);

                        if ($isAbsent) {
                            foreach ($criteria as $criterion) {
                                Mark::create([
                                    'institution_id' => $institution->id,
                                    'assessment_id' => $assessment->id,
                                    'student_id' => $student->id,
                                    'criterion_id' => $criterion->id,
                                    'mark_kind' => 'absent',
                                    'last_edited_by' => $teacher->id,
                                ]);
                            }

                            continue;
                        }

                        $targetPct = $trends['declining'][$key][$sitting]
                            ?? $trends['improving'][$key][$sitting]
                            ?? $baselines[$key] + $this->random->normal(0, 5);
                        $targetPct = max(2, min(100, $targetPct));

                        $total = 0;
                        foreach ($criteria as $criterion) {
                            $criterionPct = max(0, min(100, $targetPct + $this->random->normal(0, 3)));
                            $score = (int) round($criterion->max_score * $criterionPct / 100);

                            Mark::create([
                                'institution_id' => $institution->id,
                                'assessment_id' => $assessment->id,
                                'student_id' => $student->id,
                                'criterion_id' => $criterion->id,
                                'mark_kind' => 'score',
                                'score' => $score,
                                'last_edited_by' => $teacher->id,
                            ]);

                            $total += $score;
                        }

                        Result::create([
                            'institution_id' => $institution->id,
                            'assessment_id' => $assessment->id,
                            'student_id' => $student->id,
                            'total' => $total,
                            'max' => $maxTotal,
                            'level' => $this->levelFor($total / $maxTotal * 100),
                        ]);
                    }
                }
            }
        }
    }

    private function levelFor(float $percentage): string
    {
        return match (true) {
            $percentage >= 80 => 'EE',
            $percentage >= 60 => 'ME',
            $percentage >= 40 => 'AE',
            default => 'BE',
        };
    }
}
