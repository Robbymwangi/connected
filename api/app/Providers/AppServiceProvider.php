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
        // #43: two limits apply together. Five a minute keyed by email + IP
        // stops repeated guessing against one account; alone, that key
        // would let one address cycle through many different emails at
        // five guesses each with nothing to stop it, so a second limit
        // caps that same address at twenty a minute regardless of which
        // email it is guessing.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    Str::transliterate(Str::lower($request->string('email'))).'|'.$request->ip()
                ),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
