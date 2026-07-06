<?php
namespace App\Http\Controllers\Api\v1;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\ActivityLog\ActivityService; // Import the service

class UserController extends Controller
{
    protected $activity;

    // Inject the service here
    public function __construct(ActivityService $activity)
    {
        $this->activity = $activity;
    }

  public function updateAccess(Request $request, $id)
{
    $user = User::findOrFail($id);
    $user->access = $request->input('access');
    $user->save();

    $this->activity->log(
        auth()->id(), 
        "Changed access status for user: {$user->name} (ID: {$id})",
        auth()->user()->agency_id 
    );

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
            'access' => true,
        ]);

        // Use the injected service
        $this->activity->log(
            auth()->id(), 
            "Created new user: {$user->name}",
            auth()->user()->agency_id
        );

        return response()->json($user, 201);
    }
    public function destroy($id)
{
    $user = User::findOrFail($id);
    $userName = $user->name;

   
    $this->activity->log(
        auth()->id(), 
        "Deleted user: {$userName}",
        auth()->user()->agency_id 
    );

    $user->delete();

    return response()->json(['message' => 'User deleted successfully'], 200);
}
}