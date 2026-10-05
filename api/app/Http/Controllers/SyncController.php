<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Comment;
use App\Models\Conflict;
use App\Models\Criterion;
use App\Models\Enrolment;
use App\Models\Mark;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Result;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectModeration;
use App\Models\TeacherAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    private const SYNCABLE_MODELS = [
        Assessment::class,
        ClassSubject::class,
        Comment::class,
        Conflict::class,
        Criterion::class,
        Enrolment::class,
        Mark::class,
        Notification::class,
        Report::class,
        Result::class,
        SchoolClass::class,
        Student::class,
        Subject::class,
        SubjectModeration::class,
        TeacherAssignment::class,
        User::class,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['sometimes', 'nullable', 'date'],
        ]);

        $since = isset($validated['since'])
            ? CarbonImmutable::parse($validated['since'])->utc()
            : CarbonImmutable::createFromTimestamp(0, 'UTC');
        $cursor = CarbonImmutable::now('UTC');

        $changes = collect(self::SYNCABLE_MODELS)
            ->flatMap(function (string $modelClass) use ($since, $cursor): array {
                return $modelClass::query()
                    ->withTrashed()
                    ->where('updated_at', '>', $since)
                    ->where('updated_at', '<=', $cursor)
                    ->orderBy('updated_at')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($record): array => [
                        'table' => $record->getTable(),
                        'recordId' => $record->getKey(),
                        'version' => $record->version,
                        'updatedAt' => $record->updated_at->toISOString(),
                        'record' => $record->toArray(),
                    ])
                    ->all();
            })
            ->values();

        return response()->json([
            'changes' => $changes,
            'cursor' => $cursor->toISOString(),
        ]);
    }
}
