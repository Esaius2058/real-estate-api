<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{


public function updateAccess(Request $request, $id)
{
    // 1. Your existing logic to update the user's access
    $user = User::findOrFail($id);
    $user->access = $request->input('access');
    $user->save();

    // 2. The new automated logging line (using your correct table name)
    DB::table('activity_logs')->insert([
        'agency_id' => auth()->user()->agency_id, // Ensure this exists in your user model
        'user_id' => auth()->id(), // The admin who performed the action
        'action' => "Changed access status for user: {$user->name} (ID: {$id})",
        'ip_address' => $request->ip(),
        'created_at' => now(),
        'updated_at' => now()
    ]);

    return response()->json(['success' => true]);
}


public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'role' => 'required|string',
        'password' => 'required|string|min:8',
    ]);

    $user = User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'role' => $validated['role'],
        'password' => bcrypt($validated['password']),
        'access' => true, // Default access
    ]);

    return response()->json($user, 201);
}


private function logActivity($action)
{
    DB::table('activity_logs')->insert([
        'agency_id' => auth()->user()->agency_id,
        'user_id' => auth()->id(),
        'action' => $action,
        'ip_address' => request()->ip(),
        'created_at' => now(),
        'updated_at' => now()
    ]);
}
}