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
        // Allow if user is the direct creator OR belongs to the same agency
        return $user->id === $property->user_id || $user->agency_id === $property->agency_id;
    }

    /**
     * Determine whether the user can delete the model.
     * Usually mirrors the update logic.
     */
    public function delete(User $user, Property $property): bool
    {
        return $user->id === $property->user_id || $user->agency_id === $property->agency_id;
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