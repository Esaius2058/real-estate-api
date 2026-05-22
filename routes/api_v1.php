<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\EscrowWebhookController;
use App\Http\Controllers\Api\v1\PaymentController;

// Public Routes
Route::post('/register', [AuthenticationController::class, 'register']);
Route::post('/login', [AuthenticationController::class, 'login']);

// External Webhooks (No Auth)
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
    
    // Payments (with dedicated rate limiting)
    Route::post('/payments/initiate', [PaymentController::class, 'initiate'])
        ->middleware('throttle:payments');
});