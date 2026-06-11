<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // 1. Failsafe: User must be authenticated
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 2. Normalize the database role to lowercase for safe comparison
        $userRole = strtolower(trim($user->role));

        // 3. Strict Check: Is the user's role in the array of allowed roles?
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message' => 'Security Clearance Denied. You lack the required permissions for this endpoint.',
                'required_roles' => $roles,
                'your_role' => $userRole
            ], 403);
        }

        return $next($request);
    }
}