<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MeController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/me', MeController::class)->name('me');
});
