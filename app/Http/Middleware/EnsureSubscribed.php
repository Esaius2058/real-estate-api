<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next, string $tier = 'standard'): Response
    {
        $user = $request->user();

        if (!$user || !$user->subscription || $user->subscription->status !== 'active') {
            return response()->json([
                'error' => 'Subscription Required',
                'message' => 'Please upgrade your operational tier plan layout access.'
            ], 403);
        }

        return $next($request);
    }
}