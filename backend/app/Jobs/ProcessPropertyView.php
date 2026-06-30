<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessPropertyView implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $propertyId;
    public $ipAddress;
    public $userId;

    /**
     * Create a new job instance.
     */
    public function __construct($propertyId, $ipAddress, $userId = null)
    {
        $this->propertyId = $propertyId;
        $this->ipAddress = $ipAddress;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // 1. Anti-Spam Lock (Unique Views Only)
            // Creates a unique cache key for this property + IP combination
            $cacheKey = "view_lock:prop_{$this->propertyId}:ip_{$this->ipAddress}";

            // If the IP has viewed this exact property in the last 24 hours, silently exit
            if (Cache::has($cacheKey)) {
                return; 
            }

            // Lock this IP out of triggering another view for 24 hours
            Cache::put($cacheKey, true, now()->addHours(24));

            // 2. High-Fidelity Analytics Logging
            // Insert the record into your activity logs for agency dashboards
            DB::table('activity_logs')->insert([
                'user_id'     => $this->userId, // Will be null for anonymous public traffic
                'agency_id'   => $this->agency_id,
                'ip_address'  => $this->ipAddress,
                'action'      => 'property_view',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Optional: If your properties table has a direct views_count column for fast sorting,
            // you can increment it here as well:
            // DB::table('properties')->where('id', $this->propertyId)->increment('views_count');

        } catch (\Exception $e) {
            // Failsafes: Never let an analytics error crash the queue worker
            Log::error("Failed to process property view for ID {$this->propertyId}: " . $e->getMessage());
        }
    }
}