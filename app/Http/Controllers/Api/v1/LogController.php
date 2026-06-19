<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    public function index()
    {
        // Fetch from activity_logs
        $logs = DB::table('activity_logs')
            ->join('users', 'activity_logs.user_id', '=', 'users.id')
            ->select('activity_logs.id', 'activity_logs.action', 'activity_logs.created_at', 'users.name as user_name')
            ->orderBy('activity_logs.created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json($logs);
    }
}