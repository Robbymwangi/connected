<?php

use App\Http\Middleware\ResolveInstitution;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The whole api group, so no route can forget it, and given explicit
        // priority ahead of auth:sanctum (#43): Laravel's default priority
        // list runs AuthenticatesRequests before any middleware that isn't
        // in the list, so without this, auth:sanctum would resolve the
        // token's user, and load it through InstitutionScope, before
        // ResolveInstitution has reset() the request's institution context.
        // A single request in a single PHP-FPM process would still get the
        // right answer either way, from CurrentInstitution's unset default;
        // a long-lived worker (Octane, the queue worker) reusing the
        // container across requests or jobs would not, carrying the
        // previous one's institution into that resolution instead.
        $middleware->appendToGroup('api', ResolveInstitution::class);
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveInstitution::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
