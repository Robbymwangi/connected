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
        // Singleton, not the container's default per-resolution instance:
        // ResolveInstitution's set() and InstitutionScope's id() must see
        // the same instance within one request, or the state never
        // actually propagates from one to the other.
        $this->app->singleton(CurrentInstitution::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
