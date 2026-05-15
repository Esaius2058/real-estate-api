<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthenticationController;
use App\Http\Controllers\Api\V1\Property\PropertyController;
use App\Http\Controllers\Api\V1\Lead\LeadController;
use App\Http\Controllers\Api\V1\Lead\LeadKanbanController;
use App\Http\Controllers\Api\V1\Transaction\EscrowWebhookController;

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