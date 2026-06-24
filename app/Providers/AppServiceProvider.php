<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use App\Models\Property;
use App\Policies\PropertyPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    protected $listen = [
    \Illuminate\Auth\Events\Login::class => [
        \App\Listeners\LogSuccessfulLogin::class,
    ],
];
    public function register(): void
    {
        $this->app->singleton('PaystackService', function ($app) {
        return new \App\Services\PaystackService(config('services.paystack.secret'));
     });
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
    }
}