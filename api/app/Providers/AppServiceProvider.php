<?php

namespace App\Providers;

use App\Support\CurrentInstitution;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: ResolveInstitution's set and InstitutionScope's
        // read must share one instance within a request, and a long-lived
        // worker must get a fresh one for the next request or job.
        $this->app->scoped(CurrentInstitution::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // #43: keyed by email + IP, not IP alone, so one attacker guessing
        // many accounts from one address doesn't get 5/minute per guess,
        // and not email alone, so it can't be used to lock another account
        // out from a different address.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                Str::transliterate(Str::lower($request->string('email'))).'|'.$request->ip()
            );
        });
    }
}
