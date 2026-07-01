<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\ChatMessage;

class ChatController extends Controller
{
    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string'
        ]);

        $user = $request->user();
        if (!$user || !$user->agency_id) {
            return response()->json(['error' => 'Unauthorized tenant context'], 403);
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Connection' => 'close',
                ])
                ->post(config('services.agent.url') . '/agents/chat', [
                    'message' => $request->message,
                    'session_id' => $request->session_id,
                    'agency_id' => $user->agency_id, // Pass as integer
                ]);

            // LOG THE ACTUAL PYTHON ERROR FOR DEBUGGING
            if ($response->failed()) {
                \Log::error("Python Agent Error: " . $response->body());
                return response()->json([
                    'error' => 'Agent service execution failed',
                    'details' => $response->json() ?? $response->body()
                ], 502);
            }

            $data = $response->json();

            // Check if the expected key exists
            if (!isset($data['response'])) {
                return response()->json(['error' => 'Invalid response format from agent'], 502);
            }

            // Persist history
            ChatMessage::create([
                'session_id' => $request->session_id, 
                'agency_id'  => $user->agency_id,
                'role'       => 'user', 
                'content'    => $request->message
            ]);
            
            ChatMessage::create([
                'session_id' => $request->session_id, 
                'agency_id'  => $user->agency_id,
                'role'       => 'model', 
                'content'    => $data['response']
            ]);

            return response()->json(['response' => $data['response']]);

        } catch (\Exception $e) {
            \Log::error("Agent Proxy General Error: " . $e->getMessage());
            return response()->json(['error' => 'Could not connect to agent engine'], 503);
        }
    }
}