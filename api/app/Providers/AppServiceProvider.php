<?php

namespace App\Providers;

use App\Support\CurrentInstitution;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
