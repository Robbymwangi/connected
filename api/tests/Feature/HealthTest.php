<?php

use App\Http\Middleware\ResolveInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/* Ticket #44 (docs/build-plan.md 1.6): GET /api/health is what a device asks
   before it has anything else, including a token, so it answers without
   authentication, is never cached, and does no work that could fail for a
   reason other than "the API is not reachable". The frontend's connectivity
   probe (frontend/src/lib/health.ts) reads this route. */

test('the health endpoint answers ok without a token', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['ok' => true]);
});

test('the response is never cacheable, by the browser, a proxy, or the service worker', function () {
    $response = $this->getJson('/api/health');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('it does not touch the database, even when the request carries a token', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->withToken('1|a-token-that-does-not-exist')->getJson('/api/health')->assertOk();

    expect($queries)->toBe([]);
});

test('it is outside authentication, institution resolution, and the throttle', function () {
    $middleware = Route::getRoutes()->getByName('health')->gatherMiddleware();
    $resolved = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('health'));

    expect($resolved)
        ->not->toContain('auth:sanctum')
        ->not->toContain(ResolveInstitution::class)
        ->not->toContain('throttle:api');

    expect($middleware)->not->toContain('auth:sanctum');
});
