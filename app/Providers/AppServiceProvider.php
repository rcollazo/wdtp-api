<?php

namespace App\Providers;

use App\Models\Industry;
use App\Models\Organization;
use App\Models\WageReport;
use App\Observers\IndustryObserver;
use App\Observers\OrganizationObserver;
use App\Observers\WageReportObserver;
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
        WageReport::observe(WageReportObserver::class);

        // Initialize cache versions
        \Illuminate\Support\Facades\Cache::add('orgs:ver', 1, 0);
        \Illuminate\Support\Facades\Cache::add('wages:ver', 1, 0);
        \Illuminate\Support\Facades\Cache::add('locations:ver', 1, 0);
    }
}
