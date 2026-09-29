<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\ResolveInstitution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/* Outside authentication, the institution scope, and the throttle: it is
   what a device asks before it has anything else (#44). ResolveInstitution
   is excluded because it would look up a stale bearer token on every poll,
   and the throttle because a probe that gets rate limited reads as an
   unreachable API. No throttle is configured yet; the exclusion keeps it
   that way when one is. */
Route::get('/health', HealthController::class)
    ->name('health')
    ->withoutMiddleware([ResolveInstitution::class, 'throttle:api']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
