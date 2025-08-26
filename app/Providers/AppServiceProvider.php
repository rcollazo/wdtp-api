<?php

namespace App\Providers;

use App\Models\Industry;
use App\Models\Organization;
use App\Models\WageReport;
use App\Observers\IndustryObserver;
use App\Observers\OrganizationObserver;
use App\Observers\WageReportObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Configure rate limiters
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Rate limiter for wage report submissions
        RateLimiter::for('wage-reports', function (Request $request) {
            // More restrictive for anonymous users, more lenient for authenticated users
            if ($request->user()) {
                // Authenticated users: 10 submissions per hour
                return Limit::perHour(10)->by($request->user()->id);
            } else {
                // Anonymous users: 3 submissions per hour per IP
                return Limit::perHour(3)->by($request->ip());
            }
        });

        // General API rate limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
