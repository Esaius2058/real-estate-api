<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\AgencyController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\VaultDocumentController;

// ── Public Routes ────────────────────────────────────────────────────────
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// ── Protected Ecosystem ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    
    // Universal Auth & State
    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::get('/me', [AuthenticationController::class, 'me']);

    // Universal Payments
    Route::post('/payments/stk-push', [PaymentController::class, 'stkPush']);
    Route::get('/payments/status/{checkoutRequestID}', [PaymentController::class, 'checkStatus']);

    // Workspace Initialization (Limbo State)
    Route::post('/vault/initialize-workspace', [AgencyController::class, 'store']);
    Route::post('/agency/join', [AgencyController::class, 'join']);

    // Universal Read Access (Clients, Agents, Admins)
    Route::get('properties', [PropertyController::class, 'index']);
    Route::get('properties/{property}', [PropertyController::class, 'show']);


    // ── Staff Routes (Agents & Admins) ───────────────────────────────────
    Route::middleware('role:agent,admin')->group(function () {
        
        Route::get('/agency', [AgencyController::class, 'show']);

        // Property Mutations
        Route::post('properties', [PropertyController::class, 'store']);
        Route::put('properties/{property}', [PropertyController::class, 'update']);
        Route::delete('properties/{property}', [PropertyController::class, 'destroy']);
        Route::post('properties/{property}/images', [PropertyController::class, 'attachImage']);

        // Lead Management
        Route::apiResource('leads', LeadController::class);
        Route::patch('leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
        
        // Vault Operations (Uploads & Indexing)
        Route::get('/vault/documents', [VaultDocumentController::class, 'index']);
        Route::post('/vault/documents', [VaultDocumentController::class, 'store']);
        Route::post('/vault/presigned-url', [VaultDocumentController::class, 'generateUploadUrl']);
    });


    // ── High-Clearance Routes (Admins Only) ──────────────────────────────
    Route::middleware('role:admin')->group(function () {
        
        // Agency Configuration
        Route::put('/agency/{agency}', [AgencyController::class, 'update']);

        // KYC / Document Approval Queue
        Route::patch('/vault/documents/{document}/status', [VaultDocumentController::class, 'updateStatus']);
    });
});