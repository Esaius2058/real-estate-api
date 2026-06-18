<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    /**
     * Admins can view anything. Agents see their agency's data.
     */
    public function viewAny(User $user): bool
    {
        return true; 
    }

    public function view(User $user, Property $property): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return !is_null($user->agency_id);
    }

    public function update(User $user, Property $property): bool
    {
        // Admin Bypass
        if ($user->role === 'admin') {
            return true;
        }

        // Agent Ownership Check
        return $user->id === $property->user_id;
    }

    public function delete(User $user, Property $property): bool
    {
        // Admin Bypass
        if ($user->role === 'admin') {
            return true; 
        }
        
        // Agent Ownership Check
        return $user->id === $property->user_id;
    }

    public function restore(User $user, Property $property): bool
    {
        return $user->role === 'admin' || $user->id === $property->user_id;
    }

    public function forceDelete(User $user, Property $property): bool
    {
        return $user->role === 'admin' || $user->id === $property->user_id;
    }
}