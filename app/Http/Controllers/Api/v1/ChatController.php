<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Gemini\Laravel\Facades\Gemini;

class ChatController extends Controller
{
    public function sendMessage(Request $request)
    {
        $request->validate(['message' => 'required|string']);

        // The 'gemini-1.5-flash' model is fast and efficient for chats
        $result = Gemini::chat()
            ->startChat()
            ->sendMessage($request->message);

        return response()->json([
            'response' => $result->text()
        ]);
    }
}