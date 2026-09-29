<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Institution;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/* Ticket #42 (docs/build-plan.md 1.4): one realistic school to test every
   analytics figure against. Deterministic on purpose: a fixed-seed Mt19937
   and hardcoded names, not Faker (a dev dependency) or unseeded rand(), so
   a figure that changes between two runs means the code changed, not the
   data. Scores come from a model, not uniform noise (docs/spec/analytics.md
   is untestable against noise): each student has an ability, each subject
   an offset and a per-student strength, each sitting a difficulty, and each
   row a small effect, all combined into a share of every criterion's max.

   Planted on purpose, so each figure has something to find:
   - Two decliners per stream: strong in Term 1, falling through Term 2 and
     Term 3, so the decline flag (analytics.md, Decline and attention) fires
     in a subject scope and in Overall. They are never absent, so the
     latest row that flag reads is always a scored one.
   - Two strugglers per stream, well below the pass mark, for the attention
     list.
   - About 3% of student rows wholly absent (mark_kind absent on every
     criterion), never a null score.
   - Terms 1 and 2 finalized with results; Term 3's first CAT finalized;
     Term 3's second CAT scheduled but part-entered (so entry completeness
     falls below 100% and one row is left half-entered, exercising the
     `missing` outcome); Term 3's end of term scheduled and empty. Nothing
     is reports-generated: that status implies report rows with S3 keys
     that jobs 4.3 and 4.4 do not yet produce.

   Marks and results are bulk-inserted (about 16 000 rows) with explicit ids
   and version 0, because Eloquent per row is minutes; every mark id still
   comes from Mark::newUniqueId(), so the UUIDv5 formula lives in one place.
   Everything else goes through the models. Sign in as any user below with
   the password `password`. */
class SchoolSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const YEAR = 2026;

    private const ABSENT_RATE = 0.03;

    private const PARTIAL_ENTERED_ROWS = 12;

    /* Name, email, is_admin. Order fixes the RNG draw order downstream. */
    private const USERS = [
        'admin' => ['Ms. Wanjiru Kariuki', 'admin@school.edu', true],
        'doe' => ['Mr. John Doe', 'john.doe@school.edu', false],
        'akinyi' => ['Ms. Akinyi Odhiambo', 'akinyi@school.edu', false],
        'osei' => ['Ms. Osei Boateng', 'osei@school.edu', false],
        'kamau' => ['Mr. Kamau Mwangi', 'kamau@school.edu', false],
        'njeri' => ['Ms. Njeri Gathoni', 'njeri@school.edu', false],
        'otieno' => ['Mr. Otieno Omondi', 'otieno@school.edu', false],
        'barasa' => ['Mr. Barasa Wekesa', 'barasa@school.edu', false],
        'chebet' => ['Ms. Chebet Koech', 'chebet@school.edu', false],
    ];

    /* Rubrics: criterion name => max. English, Maths, and Science match
       frontend/src/fixtures/rubrics.ts; the other three are invented in the
       same shape. Subject maxima: English 60, Kiswahili 60, Maths 100,
       Science 100, Social Studies 50, Creative Arts 50. */
    private const RUBRICS = [
        'English' => ['Comprehension' => 20, 'Written Expr.' => 15, 'Oral Fluency' => 15, 'Vocabulary' => 10],
        'Kiswahili' => ['Ufahamu' => 20, 'Uandishi' => 15, 'Kusikiliza na Kuzungumza' => 15, 'Msamiati' => 10],
        'Maths' => ['Number Ops' => 20, 'Algebra' => 20, 'Geometry' => 15, 'Data Handling' => 15, 'Problem Solv.' => 30],
        'Science' => ['Knowledge' => 25, 'Practical' => 25, 'Analysis' => 25, 'Comm./Report' => 25],
        'Social Studies' => ['Knowledge' => 20, 'Map Skills' => 15, 'Inquiry' => 15],
        'Creative Arts' => ['Technique' => 20, 'Creativity' => 20, 'Presentation' => 10],
    ];

    /* Added to a student's ability before their own per-subject strength. */
    private const SUBJECT_OFFSET = [
        'English' => 2, 'Kiswahili' => -2, 'Maths' => -5,
        'Science' => 0, 'Social Studies' => 3, 'Creative Arts' => 6,
    ];

    /* stream => [grade, class teacher key, birth year]. */
    private const STREAMS = [
        '4W' => ['Grade 4', 'doe', 2016],
        '4E' => ['Grade 4', 'akinyi', 2016],
        '5A' => ['Grade 5', 'njeri', 2015],
        '5B' => ['Grade 5', 'osei', 2015],
    ];

    /* Who teaches each subject in each stream. English is the class
       teacher's own; the rest are specialists across streams. */
    private const TEACHING = [
        'Kiswahili' => ['4W' => 'barasa', '4E' => 'barasa', '5A' => 'chebet', '5B' => 'chebet'],
        'Maths' => ['4W' => 'kamau', '4E' => 'otieno', '5A' => 'kamau', '5B' => 'otieno'],
        'Science' => ['4W' => 'otieno', '4E' => 'kamau', '5A' => 'otieno', '5B' => 'kamau'],
        'Social Studies' => ['4W' => 'chebet', '4E' => 'chebet', '5A' => 'barasa', '5B' => 'barasa'],
        'Creative Arts' => ['4W' => 'njeri', '4E' => 'njeri', '5A' => 'doe', '5B' => 'akinyi'],
    ];

    /* user key => subjects moderated. Kamau holds two rows, the shape of a
       head of department (data-model.md, subject_moderations). */
    private const MODERATION = [
        'kamau' => ['Maths', 'Science'],
        'akinyi' => ['English'],
    ];

    /* term => [[name, date, status]] with the status the DB stores; in-progress
       and complete are derived from the grid (workflow.md), never stored. */
    private const SITTINGS = [
        1 => [['CAT 1', '2026-02-16', 'finalized'], ['CAT 2', '2026-03-09', 'finalized'], ['End of Term', '2026-03-30', 'finalized']],
        2 => [['CAT 1', '2026-06-01', 'finalized'], ['CAT 2', '2026-06-29', 'finalized'], ['End of Term', '2026-07-27', 'finalized']],
        3 => [['CAT 1', '2026-09-14', 'finalized'], ['CAT 2', '2026-09-28', 'scheduled'], ['End of Term', '2026-10-26', 'scheduled']],
    ];

    /* How far a decliner's ability falls, per term. */
    private const DECLINE_BY_TERM = [1 => 0, 2 => -6, 3 => -22];

    private const FIRST_NAMES_F = [
        'Wanjiku', 'Amina', 'Fatou', 'Nia', 'Faith', 'Mercy', 'Joy', 'Grace', 'Achieng', 'Zawadi',
        'Halima', 'Naledi', 'Imani', 'Purity', 'Ivy', 'Esther', 'Damaris', 'Lydia', 'Sharon', 'Wairimu',
    ];

    private const FIRST_NAMES_M = [
        'Kofi', 'Liam', 'Amara', 'Seun', 'Brian', 'Kevin', 'Dennis', 'Ian', 'Baraka', 'Juma',
        'Omar', 'Tendai', 'Elijah', 'Victor', 'Caleb', 'Samuel', 'Peter', 'Hassan', 'Felix', 'Mutua',
    ];

    private const SURNAMES = [
        'Njoroge', 'Mensah', 'Osei', 'Diallo', 'Kamau', 'Adeyemi', 'Otieno', 'Wambui', 'Mutua', 'Achieng',
        'Kiprotich', 'Nyambura', 'Mwangi', 'Odhiambo', 'Wekesa', 'Koech', 'Kariuki', 'Gathoni', 'Boateng', 'Omondi',
    ];

    private Randomizer $rng;

    private Institution $institution;

    public function run(): void
    {
        $this->rng = new Randomizer(new Mt19937(42));

        DB::transaction(function (): void {
            $this->institution = Institution::create(['name' => 'Mwangaza Primary School']);

            $users = $this->seedUsers();
            $subjects = $this->seedSubjects();
            $criteria = $this->seedCriteria($subjects);
            $classes = $this->seedClasses($users);
            $this->seedClassSubjects($classes, $subjects);
            $teachers = $this->seedAssignments($users, $classes, $subjects);
            $this->seedModeration($users, $subjects);
            $roster = $this->seedStudents($classes);
            $this->seedAssessmentsMarksResults($classes, $subjects, $criteria, $teachers, $roster);
        });
    }

    /** @return array<string, User> */
    private function seedUsers(): array
    {
        $users = [];

        foreach (self::USERS as $key => [$name, $email, $isAdmin]) {
            $users[$key] = User::create([
                'institution_id' => $this->institution->id,
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
                'is_admin' => $isAdmin,
            ]);
        }

        return $users;
    }

    /** @return array<string, Subject> */
    private function seedSubjects(): array
    {
        $subjects = [];

        foreach (array_keys(self::RUBRICS) as $name) {
            $subjects[$name] = Subject::create(['institution_id' => $this->institution->id, 'name' => $name]);
        }

        return $subjects;
    }

    /**
     * @param  array<string, Subject>  $subjects
     * @return array<string, list<Criterion>> subject name => criteria in rubric order
     */
    private function seedCriteria(array $subjects): array
    {
        $criteria = [];

        foreach (self::RUBRICS as $subjectName => $rubric) {
            foreach ($rubric as $criterionName => $max) {
                $criteria[$subjectName][] = Criterion::create([
                    'institution_id' => $this->institution->id,
                    'subject_id' => $subjects[$subjectName]->id,
                    'name' => $criterionName,
                    'max_score' => $max,
                ]);
            }
        }

        return $criteria;
    }

    /**
     * @param  array<string, User>  $users
     * @return array<string, SchoolClass> stream => class
     */
    private function seedClasses(array $users): array
    {
        $classes = [];

        foreach (self::STREAMS as $stream => [$grade, $teacherKey]) {
            $classes[$stream] = SchoolClass::create([
                'institution_id' => $this->institution->id,
                'grade' => $grade,
                'stream' => $stream,
                'class_teacher_id' => $users[$teacherKey]->id,
            ]);
        }

        return $classes;
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, Subject>  $subjects
     */
    private function seedClassSubjects(array $classes, array $subjects): void
    {
        foreach ($classes as $class) {
            foreach ($subjects as $subject) {
                ClassSubject::create([
                    'institution_id' => $this->institution->id,
                    'class_id' => $class->id,
                    'subject_id' => $subject->id,
                ]);
            }
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, Subject>  $subjects
     * @return array<string, array<string, User>> stream => subject name => teacher
     */
    private function seedAssignments(array $users, array $classes, array $subjects): array
    {
        $teachers = [];

        foreach (self::STREAMS as $stream => [, $classTeacherKey]) {
            foreach (array_keys(self::RUBRICS) as $subjectName) {
                $key = $subjectName === 'English' ? $classTeacherKey : self::TEACHING[$subjectName][$stream];
                $teachers[$stream][$subjectName] = $users[$key];

                TeacherAssignment::create([
                    'institution_id' => $this->institution->id,
                    'user_id' => $users[$key]->id,
                    'class_id' => $classes[$stream]->id,
                    'subject_id' => $subjects[$subjectName]->id,
                ]);
            }
        }

        return $teachers;
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Subject>  $subjects
     */
    private function seedModeration(array $users, array $subjects): void
    {
        foreach (self::MODERATION as $userKey => $subjectNames) {
            foreach ($subjectNames as $subjectName) {
                SubjectModeration::create([
                    'institution_id' => $this->institution->id,
                    'user_id' => $users[$userKey]->id,
                    'subject_id' => $subjects[$subjectName]->id,
                ]);
            }
        }
    }

    /**
     * Twenty per stream, eighty in all. Returns each stream's roster with the
     * profile the score model needs.
     *
     * @param  array<string, SchoolClass>  $classes
     * @return array<string, list<array{student: Student, base: float, decliner: bool}>>
     */
    private function seedStudents(array $classes): array
    {
        $roster = [];
        $n = 0;

        foreach (self::STREAMS as $stream => [, , $birthYear]) {
            $roles = $this->rng->shuffleArray(range(0, 19));
            $decliners = array_slice($roles, 0, 2);
            $strugglers = array_slice($roles, 2, 2);

            for ($i = 0; $i < 20; $i++, $n++) {
                $female = $n % 2 === 0;
                $first = ($female ? self::FIRST_NAMES_F : self::FIRST_NAMES_M)[intdiv($n, 2) % 20];
                $surname = self::SURNAMES[($n * 3 + intdiv($n, 20)) % 20];

                $student = Student::create([
                    'institution_id' => $this->institution->id,
                    'name' => "$first $surname",
                    'gender' => $female ? 'F' : 'M',
                    'dob' => sprintf('%d-%02d-%02d', $birthYear, $this->rng->getInt(1, 12), $this->rng->getInt(1, 28)),
                ]);

                Enrolment::create([
                    'institution_id' => $this->institution->id,
                    'student_id' => $student->id,
                    'class_id' => $classes[$stream]->id,
                    'year' => self::YEAR,
                ]);

                $isDecliner = in_array($i, $decliners, true);
                $isStruggler = in_array($i, $strugglers, true);

                $roster[$stream][] = [
                    'student' => $student,
                    'base' => match (true) {
                        $isDecliner => $this->clamp($this->normal(74, 4), 66, 82),
                        $isStruggler => $this->clamp($this->normal(38, 5), 25, 46),
                        default => $this->clamp($this->normal(62, 13), 35, 95),
                    },
                    'decliner' => $isDecliner,
                ];
            }
        }

        return $roster;
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, Subject>  $subjects
     * @param  array<string, list<Criterion>>  $criteria
     * @param  array<string, array<string, User>>  $teachers
     * @param  array<string, list<array{student: Student, base: float, decliner: bool}>>  $roster
     */
    private function seedAssessmentsMarksResults(array $classes, array $subjects, array $criteria, array $teachers, array $roster): void
    {
        $strength = [];
        $marks = [];
        $results = [];
        $subjectIndex = array_flip(array_keys(self::RUBRICS));

        foreach (array_keys(self::STREAMS) as $stream) {
            foreach (self::RUBRICS as $subjectName => $rubric) {
                $teacher = $teachers[$stream][$subjectName];
                $rubricMax = array_sum($rubric);

                foreach ($roster[$stream] as $i => $entry) {
                    $strength[$stream][$subjectName][$i] = $this->normal(0, 7);
                }

                foreach (self::SITTINGS as $term => $sittings) {
                    foreach ($sittings as [$sittingName, $date, $status]) {
                        $day = date('Y-m-d', strtotime("$date +{$subjectIndex[$subjectName]} days"));
                        $isFinalized = $status === 'finalized';
                        $isPartial = ! $isFinalized && $sittingName === 'CAT 2';
                        $isScheduledEmpty = ! $isFinalized && ! $isPartial;
                        $difficulty = $this->normal(0, 3) + ($sittingName === 'End of Term' ? -2 : 0);

                        $assessment = Assessment::create([
                            'institution_id' => $this->institution->id,
                            'class_id' => $classes[$stream]->id,
                            'subject_id' => $subjects[$subjectName]->id,
                            'name' => $sittingName,
                            'term' => $term,
                            'year' => self::YEAR,
                            'date' => $day,
                            'status' => $status,
                            'created_by' => $teacher->id,
                            'finalized_at' => $isFinalized ? "$day 15:00:00" : null,
                            'finalized_by' => $isFinalized ? $teacher->id : null,
                        ]);

                        if ($isScheduledEmpty) {
                            continue;
                        }

                        $entered = 0;

                        foreach ($roster[$stream] as $i => $entry) {
                            if ($isPartial && $entered >= self::PARTIAL_ENTERED_ROWS) {
                                break;
                            }

                            $lastPartialRow = $isPartial && $entered === self::PARTIAL_ENTERED_ROWS - 1;
                            $absent = ! $entry['decliner'] && ! $lastPartialRow && $this->rng->nextFloat() < self::ABSENT_RATE;
                            $mu = $entry['base']
                                + ($entry['decliner'] ? self::DECLINE_BY_TERM[$term] : 0)
                                + self::SUBJECT_OFFSET[$subjectName]
                                + $strength[$stream][$subjectName][$i]
                                + $difficulty
                                + $this->normal(0, 4);

                            $rowTotal = 0;
                            $rowComplete = true;

                            foreach ($criteria[$subjectName] as $c => $criterion) {
                                if ($lastPartialRow && $c >= 2) {
                                    $rowComplete = false;

                                    continue;
                                }

                                $score = null;

                                if (! $absent) {
                                    $share = $this->clamp($mu / 100 + $this->normal(0, 0.06), 0, 1);
                                    $score = (int) round($share * $criterion->max_score);
                                    $rowTotal += $score;
                                }

                                $marks[] = $this->markRow($assessment, $entry['student'], $criterion, $teacher, $day, $absent ? 'absent' : 'score', $score);
                            }

                            $entered++;

                            if ($isFinalized && ! $absent && $rowComplete) {
                                $results[] = $this->resultRow($assessment, $entry['student'], $rowTotal, $rubricMax, $day);
                            }
                        }

                    }
                }
            }
        }

        foreach (array_chunk($marks, 1000) as $chunk) {
            DB::table('marks')->insert($chunk);
        }

        foreach (array_chunk($results, 1000) as $chunk) {
            DB::table('results')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function markRow(Assessment $assessment, Student $student, Criterion $criterion, User $editor, string $day, string $kind, ?int $score): array
    {
        $mark = (new Mark)->forceFill([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'criterion_id' => $criterion->id,
        ]);

        return [
            'id' => $mark->newUniqueId(),
            'institution_id' => $this->institution->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'criterion_id' => $criterion->id,
            'mark_kind' => $kind,
            'score' => $score,
            'last_edited_by' => $editor->id,
            'version' => 0,
            'deleted_at' => null,
            'created_at' => "$day 09:00:00",
            'updated_at' => "$day 09:00:00",
        ];
    }

    /** @return array<string, mixed> */
    private function resultRow(Assessment $assessment, Student $student, int $total, int $max, string $day): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'institution_id' => $this->institution->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'total' => $total,
            'max' => $max,
            'level' => $this->level($total, $max),
            'version' => 0,
            'deleted_at' => null,
            'created_at' => "$day 15:00:00",
            'updated_at' => "$day 15:00:00",
        ];
    }

    /* The CBC thresholds of frontend/src/lib/grading.ts: 80, 60, 40 percent
       of the maximum. */
    private function level(int $total, int $max): string
    {
        $share = $total / $max;

        return match (true) {
            $share >= 0.8 => 'EE',
            $share >= 0.6 => 'ME',
            $share >= 0.4 => 'AE',
            default => 'BE',
        };
    }

    /* Box-Muller from the seeded engine; 1 - nextFloat() keeps log() off 0. */
    private function normal(float $mean, float $sd): float
    {
        $u1 = 1.0 - $this->rng->nextFloat();
        $u2 = $this->rng->nextFloat();

        return $mean + $sd * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    private function clamp(float $value, float $low, float $high): float
    {
        return max($low, min($high, $value));
    }
}
