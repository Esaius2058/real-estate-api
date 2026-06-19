<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function index()
    {
        $sessions = DB::table('sessions')
            ->join('users', 'sessions.user_id', '=', 'users.id')
            ->select(
                'sessions.id', 
                'users.name as user_name', 
                'sessions.ip_address', 
                'sessions.user_agent as browser'
            )
            ->whereNotNull('sessions.user_id') // Exclude those NULL rows
            ->orderBy('sessions.last_activity', 'desc')
            ->get();

        return response()->json($sessions);
    }
}