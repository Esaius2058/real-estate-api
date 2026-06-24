<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
 public function index()
{
    try {
        $logs = DB::table('activity_logs')
            ->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
            ->select('activity_logs.id', 'activity_logs.action', 'activity_logs.created_at', 'users.name as user_name')
            ->orderBy('activity_logs.created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json($logs);
    } catch (\Exception $e) {
        // Log the actual error internally and return a friendly JSON error
        return response()->json(['message' => 'Unable to fetch logs'], 500);
    }
}
}