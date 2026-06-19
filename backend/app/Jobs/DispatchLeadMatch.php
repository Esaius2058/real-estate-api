<?php

namespace App\Jobs;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchLeadMatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Lead $lead) {}

    public function handle(): void
    {
        $agentUrl = env('LARAVEL_API_URL', 'http://127.0.0.1:8001'); // Your Python Service URL
        
        $payload = [
            'lead_data' => $this->lead->toArray(),
            'agency_id' => $this->lead->agency_id,
        ];

        $response = Http::post($agentUrl . '/agents/match-lead', $payload);

        if ($response->failed()) {
            Log::error('Failed to dispatch Lead to AI Agent: ' . $response->body());
        }
    }
}