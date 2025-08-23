<?php

namespace App\Providers;

use App\Models\Industry;
use App\Observers\IndustryObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Industry::observe(IndustryObserver::class);

        // Initialize organizations cache version
        \Illuminate\Support\Facades\Cache::add('orgs:ver', 1, 0);
    }
}
