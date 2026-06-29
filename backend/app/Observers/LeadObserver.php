<?php

namespace App\Observers;

use App\Models\Lead;
use App\Jobs\DispatchLeadMatch;

class LeadObserver
{
    /**
     * Handle the Lead "created" event.
     */
    public function created(Lead $lead): void
    {
        if ($lead->kanban_stage === 'new') {
            DispatchLeadMatch::dispatch($lead);
        }
    }

    /**
     * Handle the Lead "updated" event.
     */
    public function updated(Lead $lead): void
    {
        // Only trigger if the stage was just changed to 'new'
        if ($lead->wasChanged('kanban_stage') && $lead->kanban_stage === 'new') {
            DispatchLeadMatch::dispatch($lead);
        }
    }
}