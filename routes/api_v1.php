<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadKanbanController;
use App\Http\Controllers\Api\V1\EscrowWebhookController;
use App\Http\Controllers\Api\V1\PaymentController;

Route::post('/register', [AuthenticationController::class, 'register']);
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/webhooks/escrow', [EscrowWebhookController::class, 'handle']);

// protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticationController::class, 'logout']);

    // properties
    Route::apiResource('properties', PropertyController::class);

    // leads
    Route::apiResource('leads', LeadController::class);
    Route::patch('leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
});

Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
Route::post('/payments/callback', [PaymentController::class, 'callback']);