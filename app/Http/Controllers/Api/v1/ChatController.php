<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Gemini\Laravel\Facades\Gemini; // Use the Facade
use Gemini\Data\Content;
use Gemini\Enums\Role;
use App\Models\ChatMessage;

class ChatController extends Controller
{
    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string'
        ]);

        // 1. Retrieve history
        $history = ChatMessage::where('session_id', $request->session_id)
            ->latest()
            ->take(10)
            ->get()
            ->reverse();

        $chatHistory = [];
        foreach ($history as $msg) {
            $chatHistory[] = Content::parse(
                part: $msg->content,
                role: $msg->role === 'user' ? Role::USER : Role::MODEL
            );
        }

        // 2. Prep system instruction
        $systemInstruction = 'You are a helpful Real Estate assistant for the agency. Provide concise, professional property insights.';
        
        $messages = array_merge(
            [Content::parse(part: $systemInstruction, role: Role::MODEL)],
            $chatHistory
        );

        // 3. Use the Facade's generativeModel method
        // Using 'gemini-3.5-flash' for stable, production-grade performance
        $chat = Gemini::generativeModel('gemini-3.5-flash')
            ->startChat(history: $messages);

        $result = $chat->sendMessage($request->message);

        if (!$result->text()) {
            return response()->json(['error' => 'Failed to get response'], 502);
        }

        // 4. Save to DB
        ChatMessage::create(['session_id' => $request->session_id, 'role' => 'user', 'content' => $request->message]);
        ChatMessage::create(['session_id' => $request->session_id, 'role' => 'model', 'content' => $result->text()]);

        return response()->json(['response' => $result->text()]);
    }
}