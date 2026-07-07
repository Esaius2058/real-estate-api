<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackfillPropertyForecasts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'properties:backfill-roi {--limit=0 : Maximum number of properties to process (0 = all)}';

    /**
     * The console command description.
     */
    protected $description = 'Scans active properties missing an ROI forecast and regenerates them via the AI Agent Service.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Scanning database for properties missing ROI forecasts...');

        $query = Property::whereIn('status', ['active', 'sold'])
            ->whereNull('roi_forecast');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $properties = $query->get();

        if ($properties->isEmpty()) {
            $this->info('All active properties already have ROI forecasts. Exiting.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$properties->count()} properties needing forecasts.");
        
        $bar = $this->output->createProgressBar($properties->count());
        $bar->start();

        $agentUrl = env('AGENT_SERVICE_URL', 'http://127.0.0.1:8001');
        $successCount = 0;
        $failCount = 0;

        foreach ($properties as $property) {
            try {
                // 1. Gather Comps (Identical logic to your controller)
                $comps = Property::where('location', 'like', "%{$property->location}%")
                    ->where('type', $property->type)
                    ->where('id', '!=', $property->id)
                    ->whereBetween('price', [$property->price * 0.75, $property->price * 1.25])
                    ->whereIn('status', ['active', 'sold'])
                    ->limit(5)
                    ->get(['id', 'title', 'price', 'status', 'bedrooms', 'baths'])
                    ->toArray();

                // 2. Build Payload
                $payload = [
                    'property_id' => $property->id,
                    'property_title' => $property->title,
                    'location' => $property->location,
                    'price' => $property->price,
                    'property_type' => $property->type,
                    'comparable_listings' => $comps,
                ];

                // 3. Dispatch to AI Agent Service
                $response = Http::timeout(60)->post($agentUrl . '/agents/roi-forecast', $payload);

                if ($response->successful()) {
                    // 4. Save to Database
                    $property->update(['roi_forecast' => $response->json()]);
                    $successCount++;
                } else {
                    Log::error("Backfill failed for Property ID {$property->id}: " . $response->body());
                    $failCount++;
                }

            } catch (\Exception $e) {
                Log::error("System error during backfill for Property ID {$property->id}: " . $e->getMessage());
                $failCount++;
            }

            // Advance the progress bar and throttle slightly to prevent DDOSing your own AI service
            $bar->advance();
            sleep(1); 
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill complete!");
        $this->line("<fg=green>Successful updates: {$successCount}</>");
        if ($failCount > 0) {
            $this->line("<fg=red>Failed updates: {$failCount} (Check Laravel logs for details)</>");
        }

        return Command::SUCCESS;
    }
}