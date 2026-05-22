<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

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
        RateLimiter::for('payments', function (Request $request) {
            // Limit users to 3 payment initiation attempts per minute based 0n ID or IP
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });
    }
}
