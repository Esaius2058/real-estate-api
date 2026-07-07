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
            // UPDATED: Added activity_logs.description to the select array
            ->select(
                'activity_logs.id', 
                'activity_logs.action', 
                'activity_logs.description', 
                'activity_logs.created_at', 
                'users.name as user_name'
            )
            ->orderBy('activity_logs.created_at', 'desc')
            ->limit(20) // Kept to a tight 20 items for dropdown performance
            ->get();

        return response()->json($logs);
    } catch (\Exception $e) {
        return response()->json(['message' => 'Unable to fetch logs'], 500);
    }
}
public static function logActivity($user, $action, $description = null)
{
    DB::table('activity_logs')->insert([
        'user_id'     => $user ? $user->id : null,
        'agency_id'   => $user ? $user->agency_id : null,
        'action'      => $action,
        'description' => $description,
        'ip_address'  => request()->ip(), 
        'created_at'  => now(),
    ]);
}
}