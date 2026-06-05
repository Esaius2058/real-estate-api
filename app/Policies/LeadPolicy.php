<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->role === 'admin') return true;
        
        // Ensure column name matches your DB (user_id or assigned_agent_id)
        return $user->id === $lead->assigned_agent_id; 
    }

    public function create(User $user): bool
    {
        return !is_null($user->agency_id);
    }

    public function update(User $user, Lead $lead): bool
    {
        if ($user->role === 'admin') return true;
        
        return $user->id === $lead->assigned_agent_id;
    }

    public function delete(User $user, Lead $lead): bool
    {
        if ($user->role === 'admin') return true;
        
        return $user->id === $lead->assigned_agent_id;
    }
}