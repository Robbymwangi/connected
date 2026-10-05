<?php

use App\Http\Controllers\AssessmentsController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MarksController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TeachersController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\ResolveInstitution;
use Illuminate\Support\Facades\Route;

/* Outside authentication, the institution scope, and the throttle: it is
   what a device asks before it has anything else (#44). ResolveInstitution
   is excluded because it would look up a stale bearer token on every poll,
   and the default api throttle because a probe that gets rate limited reads
   as an unreachable API. */
Route::get('/health', HealthController::class)
    ->name('health')
    ->withoutMiddleware([ResolveInstitution::class, 'throttle:api']);

// #43 (docs/build-plan.md 1.5). Login has no account yet to resolve an
// institution from, so it is the one route that reads across institutions
// on purpose (LoginController does so explicitly, per ResolveInstitution's
// own comment). Logout and /me require a token.
Route::post('/login', LoginController::class)->name('login')->middleware('throttle:login');

// Every route behind auth:sanctum takes EnsureAccountIsActive too: a token
// issued before an account was deactivated must stop working, not just a
// new login (docs/spec/data-model.md, users). Future protected routes
// (2.1 onward) should keep both in this same group rather than auth:sanctum
// alone.
Route::middleware(['auth:sanctum', EnsureAccountIsActive::class])->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/me', MeController::class)->name('me');
    Route::get('/sync', SyncController::class)->name('sync.pull');

    // 2.1 (#48): reads unrestricted within the institution for any
    // authenticated user (docs/spec/access-model.md, The principle).
    Route::get('/classes', ClassesController::class)->name('classes.index');
    Route::get('/subjects', SubjectsController::class)->name('subjects.index');
    Route::get('/students', StudentsController::class)->name('students.index');
    Route::get('/assessments', AssessmentsController::class)->name('assessments.index');
    Route::post('/marks', [MarksController::class, 'store'])->name('marks.store');
    Route::put('/marks/{mark}', [MarksController::class, 'update'])->name('marks.update');
    Route::post('/students', [StudentsController::class, 'store'])->name('students.store');
    Route::put('/students/{student}', [StudentsController::class, 'update'])->name('students.update');
    Route::post('/assessments', [AssessmentsController::class, 'store'])->name('assessments.store');
    Route::put('/assessments/{assessment}', [AssessmentsController::class, 'update'])->name('assessments.update');
    Route::post('/assessments/{assessment}/unlock', [AssessmentsController::class, 'unlock'])->name('assessments.unlock');
    Route::post('/classes', [ClassesController::class, 'store'])->name('classes.store');
    Route::put('/classes/{schoolClass}', [ClassesController::class, 'update'])->name('classes.update');
    Route::post('/teachers', [TeachersController::class, 'store'])->name('teachers.store');
    Route::put('/teachers/{teacher}', [TeachersController::class, 'update'])->name('teachers.update');
});
