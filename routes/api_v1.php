<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\AgencyController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;

// Public Routes (No 'v1/' prefix here, as the URL is already api/v1/login)
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::get('/me', [AuthenticationController::class, 'me']);

    // Properties
    Route::apiResource('properties', PropertyController::class);

    // Leads
    Route::apiResource('leads', LeadController::class);
    Route::patch('leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
    
    // Payments
    Route::post('/payments/stk-push', [PaymentController::class, 'stkPush']);
    Route::get('/payments/status/{checkoutRequestID}', [PaymentController::class, 'checkStatus']);

    // Workspace/Agency
    Route::post('/vault/initialize-workspace', [AgencyController::class, 'store']);
    Route::post('/agency/join', [AgencyController::class, 'join']);
    Route::get('/agency', [AgencyController::class, 'show']);
    Route::put('/agency/{agency}', [AgencyController::class, 'update']);
});