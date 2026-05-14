<?php

namespace App\Http\Controllers\Api\V1\Transaction;

use App\Http\Controllers\Controller;
use App\Services\Escrow\EscrowService;
use App\Services\Signature\WebhookSignatureService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EscrowWebhookController extends Controller
{
    public function __construct(
        private WebhookSignatureService $signatureService,
        private EscrowService $escrowService
    ) {}
 
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify the request came from your actual payment gateway
        if (! $this->signatureService->verify($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 2. Delegate the business logic to the Escrow Service
        $this->escrowService->processWebhookPayload($request->all());

        // 3. Always return a 200 OK fast so the gateway stops retrying
        return response()->json(['status' => 'acknowledged']);
    }
}