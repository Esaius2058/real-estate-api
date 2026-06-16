<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Core System Imports
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\AgencyController;
use App\Http\Controllers\Api\v1\AgentController; // <--- ADDED: Agent Controller
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\VaultDocumentController;
use App\Http\Controllers\Api\v1\AdminPropertyController;
use App\Http\Controllers\Api\v1\PasswordController;

// Financial Engine Imports
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\SubscriptionController;
use App\Http\Controllers\Api\v1\EscrowController;
use App\Http\Controllers\Api\v1\AdminDashboardController;
use App\Http\Controllers\Api\v1\PayoutController;

/*
|--------------------------------------------------------------------------
| API Routes — Production Environment Pipeline
|--------------------------------------------------------------------------
*/

// PUBLIC GATEWAY CHANNELS (No Authentication Required)

Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// Public Password Recovery Flows
Route::post('/password/forgot', [PasswordController::class, 'sendResetCode']); 
Route::post('/password/reset', [PasswordController::class, 'resetPassword']);

// Universal Read Access (Clients, Agents, Admins)
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/properties/shares/sign-images', [PropertyController::class, 'generatePublicSignedUrls']);

Route::apiResource('/leads', LeadController::class)->only(['store']); // Keep store public for checkout

// Inbound Automated Financial Webhooks 
Route::post('/payments/callback', [PaymentController::class, 'callback']); // Safaricom Daraja STK Push Callback Engine
Route::post('/paystack/webhook', [SubscriptionController::class, 'webhook']); // Paystack Event Webhook Handler
Route::get('/paystack/callback', [PaymentController::class, 'verifyPaystack']); // Paystack Frontend Redirect Callback Verify Endpoint

Route::prefix('payouts')->group(function () {
    Route::post('/result', [PayoutController::class, 'handleMpesaResult']); // Safaricom B2C Processing Result
    Route::post('/timeout', [PayoutController::class, 'handleMpesaResult']); // Safaricom B2C Timeout Queue Fallback
});


// PROTECTED SYSTEM WORKSPACE LAYERS (Sanctum Guarded)
Route::middleware('auth:sanctum')->group(function () {

    // Universal Auth & State Profiling
    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::get('/me', [AuthenticationController::class, 'me']);
    Route::post('/me', [AuthenticationController::class, 'updateProfile']);
    Route::post('/password/update', [PasswordController::class, 'update']);
    Route::get('/dashboard/summary', 'App\Http\Controllers\Api\v1\DashboardController@index');
    
    // Internal Lead Reads (Agents/Admins)
    Route::apiResource('/leads', LeadController::class)->except(['store']);

    // Workspace Vault (Generic Storage Context)
    Route::prefix('vault')->group(function () {
        Route::get('/documents', [VaultDocumentController::class, 'index']);
        Route::post('/documents', [VaultDocumentController::class, 'store']);
        Route::patch('/documents/{id}/status', [VaultDocumentController::class, 'updateStatus']);
        Route::delete('/documents/{id}', [VaultDocumentController::class, 'destroy']);
        Route::post('/presigned-upload-url', [VaultDocumentController::class, 'presignedUploadUrl']);
        Route::post('/initialize-workspace', [AgencyController::class, 'store']);
    });

    // Workspace & Agency Boundary Control
    Route::prefix('agency')->group(function () {
        Route::post('/join', [AgencyController::class, 'join']);
        Route::get('/', [AgencyController::class, 'show']);
        Route::put('/{agency}', [AgencyController::class, 'update']);
    });

    // Dynamic SaaS Subscription Systems
    Route::prefix('subscriptions')->group(function () {
        Route::get('/tiers', [SubscriptionController::class, 'getTiers']);
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
        Route::get('/current', [SubscriptionController::class, 'mySubscription']);
    });

    // Transaction & Payment Pipelines (M-Pesa / Card Processing)
    Route::prefix('payments')->group(function () {
        Route::post('/stk-push', [PaymentController::class, 'stkPush']);
        Route::get('/status/{checkoutRequestId}', [PaymentController::class, 'checkStatus']);
        Route::post('/paystack/initialize', [PaymentController::class, 'initializePaystack']);
        Route::get('/history', [PaymentController::class, 'history']); 
    });

    // Escrow Accounts & Milestones Operational Loop
    Route::prefix('escrows')->group(function () {
        Route::get('/', [EscrowController::class, 'index']); 
        Route::post('/', [EscrowController::class, 'store']); 
        Route::get('/{id}', [EscrowController::class, 'show']); 
        Route::post('/{id}/milestones', [EscrowController::class, 'addMilestone']); 
        Route::post('/milestones/{id}/approve', [EscrowController::class, 'approveMilestone']); 
        Route::post('/{id}/dispute', [EscrowController::class, 'raiseDispute']); 
    });

    // Vendor Financial Outbound Release Points
    Route::post('/payouts/milestone/{id}/release', [PayoutController::class, 'releaseMilestonePayout']);

    // Back-Office Mediation & Administration Workspace
    Route::prefix('admin')->middleware('can:manage-system')->group(function () {
        Route::get('/dashboard/metrics', [AdminDashboardController::class, 'metrics']); 
        Route::get('/disputes', [AdminDashboardController::class, 'disputes']); 
        Route::post('/disputes/{id}/resolve', [AdminDashboardController::class, 'resolveDispute']); 
    });

    // STAFF & ELEVATED ROLE CONTROLS (Role Middleware Guarded)

    // ── Staff Routes (Shared Agents & Admins Clearance) ──────────────────────
    Route::middleware('role:agent,admin')->group(function () {
        Route::get('/agency', [AgencyController::class, 'show']);
        Route::get('/agent/properties', [PropertyController::class, 'agencyIndex']);
        Route::get('/agent/properties/{property}', [PropertyController::class, 'show']);

        // Property Mutations (Strictly excluded from public scope)
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
        Route::post('/properties/marketing/generate', [PropertyController::class, 'generateMarketingCopy']);
        Route::post('/properties/{property}/images', [PropertyController::class, 'attachImage']);

        // Staff Lead Management Overrides
        Route::patch('/leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
        
        // Secure Vault Document Operations (OCR Context)
        Route::get('/vault/documents', [VaultDocumentController::class, 'index']);
        Route::post('/vault/documents', [VaultDocumentController::class, 'store']);
        Route::post('/vault/presigned-url', [VaultDocumentController::class, 'generateUploadUrl']);
    });

    // ── High-Clearance Routes (Strictly Dedicated Admins) ────────────────────
    Route::middleware('role:admin')->group(function () {
        // Core Agency Configurations
        Route::put('/agency/{agency}', [AgencyController::class, 'update']);
        
        // Agent Resource Management (Admins only)
        Route::apiResource('/agents', AgentController::class)->except(['create', 'edit', 'show']);

        // KYC / Secure Document Approval Queue
        Route::patch('/vault/documents/{document}/status', [VaultDocumentController::class, 'updateStatus']);
        
        // Admin Property Controls (Listing Verification & Purges)
        Route::get('/admin/properties', [AdminPropertyController::class, 'index']);
        Route::patch('/admin/properties/{property}/status', [AdminPropertyController::class, 'updateStatus']);
        Route::delete('/admin/properties/{id}', [AdminPropertyController::class, 'destroy']);
        Route::delete('/admin/properties/{id}/permanent', [AdminPropertyController::class, 'forceDestroy']);
    });

});