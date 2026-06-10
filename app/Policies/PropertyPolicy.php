<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    /**
     * Determine whether the user can view any models.
     * Usually true if your agents need to see the dashboard/index.
     */
    public function viewAny(User $user): bool
    {
        return true; 
    }

    /**
     * Determine whether the user can view the model.
     * Usually true, or restricted to same agency if properties are private.
     */
    public function view(User $user, Property $property): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     * Anyone with an assigned agency should be able to create a property.
     */
    public function create(User $user): bool
    {
        return !is_null($user->agency_id);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Property $property): bool
    {
        // Admins can edit any property within their agency 
        // (Agency isolation is already handled by AgencyScope on retrieval)
        if ($user->role === 'admin') {
            return true;
        }

        // Agents can ONLY edit properties they directly own
        return $user->id === $property->user_id;
    }

    public function delete(User $user, Property $property): bool
    {
        if ($user->role === 'admin') {
            return $user->agency_id === $property->agency_id;
        }
        return $user->id === $property->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Property $property): bool
    {
        return $user->id === $property->user_id || $user->agency_id === $property->agency_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Property $property): bool
    {
        return $user->id === $property->user_id || $user->agency_id === $property->agency_id;
    }
}