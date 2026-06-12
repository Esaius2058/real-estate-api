<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
// use App\Mail\LeadStageChangedMail; 

class AgencyNotificationService
{
    /**
     * Notify an agent when a lead requires urgent attention.
     */
    public function notifyAgentOfStageChange(Lead $lead, string $newStage): void
    {
        $agent = $lead->assignedAgent;

        if (!$agent) {
            return;
        }

        // Dispatching a queued Mailable
        // Mail::to($agent->email)->queue(new LeadStageChangedMail($lead, $newStage));
        
        // If integrating SMS via Africa's Talking or Twilio:
        // $this->sendSms($agent->phone, "Your lead {$lead->name} is now in stage: {$newStage}");
    }

    /**
     * Notify the agency admin of completed escrows.
     */
    public function notifyAdminOfFundedEscrow(Lead $lead): void
    {
        $admin = User::where('agency_id', $lead->agency_id)
            ->where('role', 'admin')
            ->first();

        if ($admin) {
            // Mail::to($admin->email)->queue(new EscrowFundedMail($lead));
        }
    }
}