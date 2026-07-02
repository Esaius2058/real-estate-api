<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ChatMessage;

class ChatController extends Controller
{
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => ['required', 'string', 'max:2000'],
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (!$user?->agency_id) {
            return response()->json(['error' => 'Unauthorized tenant context.'], 403);
        }

        // Enforce tenant isolation on session — agent cannot access another agency's history
        $sessionBelongsToAgency = ChatMessage::where('session_id', $request->session_id)
            ->where('agency_id', $user->agency_id)
            ->exists();

        $sessionIsNew = !ChatMessage::where('session_id', $request->session_id)->exists();

        if (!$sessionIsNew && !$sessionBelongsToAgency) {
            return response()->json(['error' => 'Session not found.'], 404);
        }

        // Fetch last 10 messages for this session to pass as context
        $history = ChatMessage::where('session_id', $request->session_id)
            ->where('agency_id', $user->agency_id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->toArray();

        // 1. ISOLATE THE HTTP REQUEST
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.agent.url') . '/agents/chat', [
                    'message'    => $request->message,
                    'session_id' => $request->session_id,
                    'agency_id'  => $user->agency_id,
                    'history'    => $history,
                ]);
        } catch (\Exception $e) {
            // This now ONLY catches actual connection timeouts/failures
            Log::error('Agent Connection Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not connect to agent engine.'], 503);
        }

        // 2. HANDLE HTTP FAILURES
        if ($response->failed()) {
            if ($response->status() === 429) {
                return response()->json(['error' => $response->json('detail') ?? 'Too many requests.'], 429);
            }
            return response()->json(['error' => 'Agent service execution failed.'], 502);
        }

        $data = $response->json();

        if (!isset($data['response'])) {
            return response()->json(['error' => 'Invalid response format from agent.'], 502);
        }

       $aiContent = is_array($data['response']) ? json_encode($data['response']) : $data['response'];

        try {
            ChatMessage::insert([
                [
                    'session_id' => $request->session_id,
                    'agency_id'  => $user->agency_id,
                    'role'       => 'user',
                    'content'    => $request->message,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
                [
                    'session_id' => $request->session_id,
                    'agency_id'  => $user->agency_id,
                    'role'       => 'model',
                    'content'    => $aiContent, // <-- Use the sanitized variable
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $e) {
            // IF IT FAILS NOW, THIS WILL CATCH IT
            Log::error('Database Insert Error', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to save chat history.', 
                'details' => $e->getMessage() // TEMPORARILY return this to your UI so you can see it
            ], 500);
        }

        return response()->json(['response' => $data['response']]);
    }
}