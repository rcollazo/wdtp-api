<?php

namespace App\Providers;

use App\Models\Industry;
use App\Models\Organization;
use App\Observers\IndustryObserver;
use App\Observers\OrganizationObserver;
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
        Organization::observe(OrganizationObserver::class);

        // Initialize organizations cache version
        \Illuminate\Support\Facades\Cache::add('orgs:ver', 1, 0);
    }
}
