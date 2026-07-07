<?php

namespace App\Providers;

use App\Models\Property;
use App\Policies\PropertyPolicy;
use App\Models\User; // Make sure to import the User model
use Illuminate\Support\Facades\Gate; // Import the Gate facade
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Property::class => PropertyPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Define the missing gate used by your admin routes middleware
        Gate::define('manage-system', function (User $user) {
            return $user->role === 'admin';
        });
    }
}