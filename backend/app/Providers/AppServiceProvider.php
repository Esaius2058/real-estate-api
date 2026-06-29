<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use App\Models\Lead;
use App\Observers\LeadObserver;
use App\Models\Property;
use App\Observers\PropertyObserver;
use App\Policies\PropertyPolicy;

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
        // 1. Explicitly register the PropertyPolicy
        Gate::policy(Property::class, PropertyPolicy::class);

        // 2. Optimized Rate Limiting
        RateLimiter::for('payments', function (Request $request) {
            // Using a higher limit for logged-in users (e.g., 10) vs guests (e.g., 3)
            // helps prevent genuine agent errors from causing a lockout.
            return $request->user() 
                ? Limit::perMinute(10)->by($request->user()->id)
                : Limit::perMinute(3)->by($request->ip());
        });

        Property::observe(PropertyObserver::class);
        Lead::observe(LeadObserver::class);
    }
}