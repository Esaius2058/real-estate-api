<?php

namespace App\Services\ActivityLog; 
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;

class ActivityService
{
    /**
     * Log an action to the activity_logs table.
     */
    public function log(?int $userId, string $action, ?int $agencyId = null): void
    {
        DB::table('activity_logs')->insert([
            'user_id'    => $userId,
            'agency_id'  => $agencyId,
            'action'     => $action,
            'ip_address' => request()->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}