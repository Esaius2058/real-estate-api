<?php

namespace App\Jobs;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchPropertyMatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Property $property) {}

    public function handle(): void
    {
        // Use a dedicated machine-to-machine token defined in your .env
        // e.g., AGENT_SERVICE_TOKEN=your_secure_random_string
        $serviceToken = config('services.agent.token');

        $response = Http::timeout(60)
            ->withToken($serviceToken)
            ->post(config('services.agent.url', 'http://127.0.0.1:8001') . '/agents/match', [
                'property_data' => $this->property->toArray(),
                'agency_id'     => $this->property->agency_id,
            ]);

        if ($response->failed()) {
            Log::error("Failed to dispatch AI match for Property {$this->property->id}", [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
        }
    }
}