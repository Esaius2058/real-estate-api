<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Property;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Fetch the native Laravel database notifications for the authenticated user
        $query = $request->user()->notifications();

        // Optional filter by notification type (e.g., ?type=PropertyMatchFound)
        if ($request->has('type')) {
            $query->where('type', 'like', '%' . $request->type . '%');
        }

        return response()->json($query->latest()->take(50)->get());
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['success' => true]);
    }
    
    public function storePropertyMatches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'matches' => 'required|array',
            'matches.*.lead_id' => 'required|integer|exists:leads,id',
            'matches.*.match_score' => 'required|integer',
            'matches.*.reasoning' => 'required|string',
        ]);

        try {
            // Eager load the agent relationship to avoid N+1 queries later
            $property = Property::with('agent')->findOrFail($validated['property_id']);
            
            // The agent variable is now the full User model object, not just an integer ID
            $agent = $property->agent; 

            foreach ($validated['matches'] as $match) {
                // If using Laravel's notification system (assuming $agent uses Notifiable):
                /*
                $agent->notify(new \App\Notifications\PropertyMatchFound([
                    'property_id' => $property->id,
                    'lead_id'     => $match['lead_id'],
                    'score'       => $match['match_score'],
                    'reasoning'   => $match['reasoning']
                ]));
                */
                
                // Temporary log now includes the agent's details
                $agentName = $agent ? $agent->name : 'Unknown Agent';
                
                Log::info("AI Match created for Property {$property->id} and Lead {$match['lead_id']} with score {$match['match_score']}. Assigned to Agent: {$agentName}");
            }

            return response()->json([
                'success' => true,
                'message' => count($validated['matches']) . ' match notifications processed.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to process property matches: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }

    public function draftProposal(Request $request)
    {
        $validated = $request->validate([
            'lead_name' => 'required|string',
            'property_title' => 'required|string',
            'location' => 'required|string',
            'price' => 'required',
            'reasoning' => 'required|string',
        ]);

        $agentUrl = env('AGENT_SERVICE_URL', 'http://127.0.0.1:8001'); 
        
        // Proxy the request to the Python Agent Service
        $response = Http::post(str_replace($agentUrl).'/agents/draft', $validated);

        if ($response->failed()) {
            return response()->json(['message' => 'AI Service unavailable'], 500);
        }

        return response()->json(['proposal' => $response->json('proposal')]);
    }

    public function predictROI(Request $request)
    {
        $validated = $request->validate([
            'property_title' => 'required|string',
            'location' => 'required|string',
            'price' => 'required|numeric',
            'property_type' => 'required|string',
        ]);

        $agentUrl = env('AGENT_SERVICE_URL', 'http://127.0.0.1:8001'); 
        
        $response = Http::post($agentUrl . '/agents/predict-roi', $validated);

        if ($response->failed()) {
            return response()->json(['message' => 'Analysis Service unavailable'], 500);
        }

        return response()->json($response->json());
    }
}