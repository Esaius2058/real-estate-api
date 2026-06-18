<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyM2MToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.agent.token', 'm2m_test_token_123');
        
        if ($request->bearerToken() !== $expectedToken) {
            return response()->json(['message' => 'Unauthorized M2M Access'], 401);
        }
        
        return $next($request);
    }
}