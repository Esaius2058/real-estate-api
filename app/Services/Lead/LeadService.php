<?php

declare(strict_types=1);

namespace App\Services\Lead;

use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Support\Facades\DB;

class LeadService
{
    /**
     * Create a lead and initialize its activity trail.
     */
    public function createLead(array $data, int $agentId): Lead
    {
        return DB::transaction(function () use ($data, $agentId) {
            $data['agent_id'] = $data['agent_id'] ?? $agentId;
            $data['kanban_stage'] = 'new';
            
            $lead = Lead::create($data);

            $this->logActivity($lead->id, $agentId, 'system', 'Lead created and assigned.');

            return $lead;
        });
    }

    /**
     * Update a lead and log significant changes.
     */
    public function updateLead(Lead $lead, array $data, int $agentId): bool
    {
        return DB::transaction(function () use ($lead, $data, $agentId) {
            $lead->update($data);

            $this->logActivity($lead->id, $agentId, 'update', 'Lead profile updated.');

            return true;
        });
    }

    /**
     * Internal method to standardize activity logging.
     */
    private function logActivity(int $leadId, int $userId, string $type, string $description): void
    {
        LeadActivity::create([
            'lead_id'     => $leadId,
            'user_id'     => $userId,
            'type'        => $type, // e.g., 'call', 'email', 'note', 'system'
            'description' => $description,
        ]);
    }
}