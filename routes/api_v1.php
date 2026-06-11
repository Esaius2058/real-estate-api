<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Core System Imports
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\AgencyController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\VaultController;

// Financial Engine Imports (From Steps 3 & 4)
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

// ==========================================
// 1. PUBLIC GATEWAY CHANNELS
// ==========================================

// Authentication Access Points
use App\Http\Controllers\Api\v1\VaultDocumentController;
use App\Http\Controllers\Api\v1\AdminPropertyController;

// ── Public Routes ────────────────────────────────────────────────────────

Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);
// Universal Read Access (Clients, Agents, Admins)
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/properties/shares/sign-images', [PropertyController::class, 'generatePublicSignedUrls']);

// Inbound Automated Financial Webhooks (Bypassing Sanctum Middleware)
Route::post('/payments/callback', [PaymentController::class, 'callback']); // Safaricom Daraja STK Push Callback Engine
Route::post('/paystack/webhook', [SubscriptionController::class, 'webhook']); // Paystack Event Webhook Handler
Route::get('/paystack/callback', [PaymentController::class, 'verifyPaystack']); // Paystack Frontend Redirect Callback Verify Endpoint

Route::prefix('payouts')->group(function () {
    Route::post('/result', [PayoutController::class, 'handleMpesaResult']); // Safaricom B2C Processing Result
    Route::post('/timeout', [PayoutController::class, 'handleMpesaResult']); // Safaricom B2C Timeout Queue Fallback
});


// ==========================================
// 2. PROTECTED SYSTEM WORKSPACE LAYERS (Sanctum Guarded)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {

    // Identity Profiling
// ── Protected Ecosystem ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    
    // Universal Auth & State

    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::get('/me', [AuthenticationController::class, 'me']);
    Route::get('/dashboard/summary', 'App\Http\Controllers\Api\v1\DashboardController@index');


    // Core Property Management Resources
    Route::apiResource('properties', PropertyController::class);

    // Lead Conversion Pipeline & Kanban Matrix
    Route::apiResource('leads', LeadController::class);
    Route::patch('leads/{lead}/kanban', [LeadKanbanController::class, 'update']);

    // Vault Digital Asset Storage Engine
    Route::prefix('vault')->group(function () {
        Route::get('/documents', [VaultController::class, 'index']);
        Route::post('/documents', [VaultController::class, 'store']);
        Route::patch('/documents/{id}/status', [VaultController::class, 'updateStatus']);
        Route::delete('/documents/{id}', [VaultController::class, 'destroy']);
        Route::post('/presigned-upload-url', [VaultController::class, 'presignedUploadUrl']);
        Route::post('/initialize-workspace', [AgencyController::class, 'store']);
    });

    // Workspace & Agency Boundary Control
    Route::prefix('agency')->group(function () {
        Route::post('/join', [AgencyController::class, 'join']);
        Route::get('/', [AgencyController::class, 'show']);
        Route::put('/{agency}', [AgencyController::class, 'update']);
    });

    // ==========================================
    // FINANCIAL SUB-SYSTEMS & ACCOUNTING PIPELINES
    // ==========================================

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
        Route::get('/history', [PaymentController::class, 'history']); // Maps to history pagination query payload
    });

    // Escrow Accounts & Milestones Operational Loop
    Route::prefix('escrows')->group(function () {
        Route::get('/', [EscrowController::class, 'index']); // Contextual workspace scan index
        Route::post('/', [EscrowController::class, 'store']); // Create transaction agreement allocation
        Route::get('/{id}', [EscrowController::class, 'show']); // Detailed metric tracker parsing
        Route::post('/{id}/milestones', [EscrowController::class, 'addMilestone']); // Attach target performance metrics
        Route::post('/milestones/{id}/approve', [EscrowController::class, 'approveMilestone']); // Client approval phase confirmation
        Route::post('/{id}/dispute', [EscrowController::class, 'raiseDispute']); // Inject conflict logging state
    });

    // Vendor Financial Outbound Release Points
    Route::post('/payouts/milestone/{id}/release', [PayoutController::class, 'releaseMilestonePayout']);

    // ==========================================
    // 3. BACK-OFFICE MEDIATION & ADMINISTRATION WORKSPACE
    // ==========================================
    Route::prefix('admin')->middleware('can:manage-system')->group(function () {
        Route::get('/dashboard/metrics', [AdminDashboardController::class, 'metrics']); // Core transactional metrics telemetry
        Route::get('/disputes', [AdminDashboardController::class, 'disputes']); // Multi-tenant system dispute log
        Route::post('/disputes/{id}/resolve', [AdminDashboardController::class, 'resolveDispute']); // Final binding arbitration override execution
    });
    // Universal Payments
    Route::post('/payments/stk-push', [PaymentController::class, 'stkPush']);
    Route::get('/payments/status/{checkoutRequestID}', [PaymentController::class, 'checkStatus']);

    // Workspace Initialization (Limbo State)
    Route::post('/vault/initialize-workspace', [AgencyController::class, 'store']);
    Route::post('/agency/join', [AgencyController::class, 'join']);

    // ── Staff Routes (Agents & Admins) ───────────────────────────────────
    Route::middleware('role:agent,admin')->group(function () {
        
        Route::get('/agency', [AgencyController::class, 'show']);

        Route::get('/agent/properties', [PropertyController::class, 'agencyIndex']);
        Route::get('/agent/properties/{property}', [PropertyController::class, 'show']);

        // Property Mutations
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
        Route::post('/properties/{property}/images', [PropertyController::class, 'attachImage']);

        // Lead Management
        Route::apiResource('/leads', LeadController::class);
        Route::patch('/leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
        
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
    
    // Admin Property Management
    
  Route::delete('/admin/properties/{id}', [AdminPropertyController::class, 'destroy']);
 Route::delete('/admin/properties/{id}/permanent', [AdminPropertyController::class, 'forceDestroy']);

 Route::middleware('role:admin')->group(function () {


    Route::get('/admin/properties', [AdminPropertyController::class, 'index']);
    Route::patch('/admin/properties/{property}/status', [AdminPropertyController::class, 'updateStatus']);
     
    // Property Management (Soft Delete)
    Route::delete('/admin/properties/{id}', [AdminPropertyController::class, 'destroy']);
    // Property Management (Permanent Wipe)
    Route::delete('/admin/properties/{id}/permanent', [AdminPropertyController::class, 'forceDestroy']);
});
});
});