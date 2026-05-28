<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\EscrowWebhookController;
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\AgencyController;

// Wrap everything in a v1 prefix group so routes match 'api/v1/...'
Route::prefix('v1')->group(function () {

    // Public Routes
    Route::post('/register', [AuthenticationController::class, 'register']);
    Route::post('/login', [AuthenticationController::class, 'login']);

    // External Webhooks (No Auth required for Daraja callback)
    Route::post('/webhooks/escrow', [EscrowWebhookController::class, 'handle']);
    Route::post('/payments/callback', [PaymentController::class, 'callback']);

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthenticationController::class, 'logout']);
        Route::get('/me', [AuthenticationController::class, 'me']);

        // Properties
        Route::apiResource('properties', PropertyController::class);

        // Leads
        Route::apiResource('leads', LeadController::class);
        Route::patch('leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
        
        // Payments & M-Pesa STK Push Engine
        Route::post('/payments/initiate', [PaymentController::class, 'initiate'])->middleware('throttle:payments');
        Route::post('/payments/stk-push', [PaymentController::class, 'stkPush']);
        Route::get('/payments/status/{checkoutRequestID}', [PaymentController::class, 'checkStatus']);

        // Agencies
        Route::get('/agency', [AgencyController::class, 'show']);
        Route::put('/agencies/{agency}', [AgencyController::class, 'update']);
    });

    Route::post('/vault/initialize-workspace', [AgencyController::class, 'store']);
    Route::post('/agency/join',                [AgencyController::class, 'join']);
    Route::get('/agency',                      [AgencyController::class, 'show']);
    Route::put('/agency/{agency}',             [AgencyController::class, 'update']);
});