<?php

namespace App\Providers;

use App\Support\Anthropic\Messages;
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

        // Read at resolve time, so a test or a changed config sees the current key.
        $this->app->bind(Messages::class, fn () => Messages::fromConfig());
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

        // The report assistant (ADR 0013, decision 2): every question is a live model call with
        // tool round trips, so a teacher gets ten a minute and sixty an hour.
        RateLimiter::for('assistant', function (Request $request) {
            $key = (string) $request->user()?->getAuthIdentifier();

            // Distinct keys: two limits sharing one key would share one counter.
            return [Limit::perMinute(10)->by('minute:'.$key), Limit::perHour(60)->by('hour:'.$key)];
        });
    }
}
