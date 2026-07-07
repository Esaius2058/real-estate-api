<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Core System Imports
use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\AdminPropertyController;
use App\Http\Controllers\Api\v1\AgencyController;
use App\Http\Controllers\Api\v1\AgentController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\VaultController;
use App\Http\Controllers\Api\v1\VaultDocumentController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\PasswordController;
use App\Http\Controllers\Api\v1\ChatController;
use App\Http\Controllers\Api\v1\LogController;
use App\Http\Controllers\Api\v1\SessionController;
use App\Http\Controllers\Api\v1\AlertController;
use App\Http\Controllers\Api\v1\AdminDashboardController;
use App\Http\Controllers\Api\v1\DashboardController;
use App\Http\Controllers\Api\v1\OtpAuthController;
use App\Http\Controllers\Api\v1\TwoFactorController;
use App\Http\Controllers\Api\v1\InternalAiController;
use App\Http\Controllers\Api\v1\AgentInventoryController;

// Financial Engine Imports
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\SubscriptionController;
use App\Http\Controllers\Api\v1\EscrowController;
use App\Http\Controllers\Api\v1\PayoutController;
use App\Http\Controllers\Api\v1\AdminEscrowController;
use App\Http\Controllers\Api\v1\AdminTransactionController;

// Middlewares
use App\Http\Middleware\VerifyM2MToken;

/*
|--------------------------------------------------------------------------
| API Routes — Production Environment Pipeline
|--------------------------------------------------------------------------
*/

// =========================================================================
// 1. PUBLIC GATEWAY CHANNELS (No Authentication Required)
// =========================================================================

Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// OTP Authentication
Route::prefix('auth/otp')->group(function () {
    Route::post('/request', [OtpAuthController::class, 'requestOtp']);
    Route::post('/verify',  [OtpAuthController::class, 'verifyOtp']);
});

// Password Recovery
Route::post('/password/forgot', [PasswordController::class, 'sendResetCode']);
Route::post('/password/reset',  [PasswordController::class, 'resetPassword']);

// Universal Read Access
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/properties/shares/sign-images', [PropertyController::class, 'generatePublicSignedUrls']);

// Public Lead Creation (e.g., Checkout/Contact Forms)
Route::apiResource('/leads', LeadController::class)->only(['store']);

// Chat Channel (Pending Auth)
Route::post('/chat', [ChatController::class, 'sendMessage']);

// Inbound Automated Financial Webhooks
Route::post('/payments/callback', [PaymentController::class, 'callback']); 
Route::post('/paystack/webhook', [SubscriptionController::class, 'webhook']); 
Route::get('/paystack/callback', [PaymentController::class, 'verifyPaystack']); 

Route::prefix('payouts')->group(function () {
    Route::post('/result', [PayoutController::class, 'handleMpesaResult']); 
    Route::post('/timeout', [PayoutController::class, 'handleMpesaResult']); 
});

// =========================================================================
// 2. PROTECTED SYSTEM WORKSPACE LAYERS (Sanctum Guarded)
// =========================================================================

Route::middleware('auth:sanctum')->group(function () {

    // Universal Auth & State Profiling
    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::get('/me', [AuthenticationController::class, 'me']);
    Route::post('/me', [AuthenticationController::class, 'updateProfile']);
    Route::post('/password/update', [PasswordController::class, 'update']);

    // Notifications
    Route::get('/me/notifications', [AlertController::class, 'index']);
    Route::post('/me/notifications/read', [AlertController::class, 'markAsRead']);

    // 2FA Settings
    Route::prefix('settings/2fa')->group(function () {
        Route::post('/request', [TwoFactorController::class, 'requestEnable']);
        Route::post('/enable',  [TwoFactorController::class, 'confirmEnable']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
    });

    // Dashboards
    Route::get('/dashboard/summary', [DashboardController::class, 'index']);

    // Lead Management (Protected Reads/Updates)
    Route::apiResource('/leads', LeadController::class)->except(['store']);

    // Workspace Vault (Generic Storage Context)
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

    // Dynamic SaaS Subscription Systems
    Route::prefix('subscriptions')->group(function () {
        Route::get('/tiers', [SubscriptionController::class, 'getTiers']);
        Route::post('/subscribe-mpesa', [SubscriptionController::class, 'subscribeMpesa']);
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
        Route::get('/current', [SubscriptionController::class, 'mySubscription']);
    });

    // Transaction & Payment Pipelines
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
        Route::get('/my-escrows', [EscrowController::class, 'myEscrows']);
        Route::get('/verify/{reference}', [EscrowController::class, 'verifyPayment']);
        Route::post('/deposit', [EscrowController::class, 'initializeDeposit']);
        Route::get('/{id}', [EscrowController::class, 'show']);
        Route::get('/{id}/timeline', [EscrowController::class, 'timeline']);
        Route::post('/{id}/release', [EscrowController::class, 'release']);
        Route::post('/{id}/refund', [EscrowController::class, 'refund']);
        Route::post('/{id}/request-inspection', [EscrowController::class, 'requestInspection']);
        Route::post('/{id}/fund', [EscrowController::class, 'recordFundingAllocation']);
        Route::post('/{id}/milestones', [EscrowController::class, 'addMilestone']);
        Route::post('/{id}/dispute', [EscrowController::class, 'raiseDispute']);
        Route::post('/milestones/{id}/approve', [EscrowController::class, 'approveMilestone']);
    });

    // Vendor Financial Outbound Release Points
    Route::post('/payouts/milestone/{id}/release', [PayoutController::class, 'releaseMilestonePayout']);

    // AI Auxiliary Endpoints
    Route::post('/matches/draft', [AlertController::class, 'draftProposal']);
    Route::get('/properties/comps', [PropertyController::class, 'getComps']);
    Route::post('/properties/predict-roi', [PropertyController::class, 'predictROI']);

    // =========================================================================
    // 3. STAFF & ELEVATED ROLE CONTROLS (Role Middleware Guarded)
    // =========================================================================

    // ── Staff Routes (Shared Agents & Admins Clearance) ──
    Route::middleware('role:agent,admin')->group(function () {
        Route::get('/agency', [AgencyController::class, 'show']);
        Route::get('/agent/properties', [PropertyController::class, 'agencyIndex']);
        Route::get('/agent/properties/{property}', [PropertyController::class, 'show']);

        // Property Mutations
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
        Route::post('/properties/{property}/images', [PropertyController::class, 'attachImage']);
        Route::post('/properties/marketing/generate', [PropertyController::class, 'generateMarketingCopy']);

        // Staff Lead Management Overrides
        Route::patch('/leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
        
        // Secure Vault Document Operations
        Route::get('/vault/documents', [VaultDocumentController::class, 'index']);
        Route::post('/vault/documents', [VaultDocumentController::class, 'store']);
        Route::post('/vault/presigned-url', [VaultDocumentController::class, 'generateUploadUrl']);
    });

    // ── High-Clearance Routes (Strictly Dedicated Admins) ──
    Route::middleware('role:admin')->group(function () {
        
        // Core Agency & Agent Controls
        Route::put('/agency/{agency}', [AgencyController::class, 'update']);
        Route::apiResource('/agents', AgentController::class)->except(['create', 'edit', 'show']);

        // User & Session Management
        Route::get('/admin/users', [AdminDashboardController::class, 'getUsers']);
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::delete('/admin/users/{id}', [UserController::class, 'destroy']);
        Route::patch('/admin/users/{id}/access', [UserController::class, 'updateAccess']);
        Route::get('/admin/logs', [LogController::class, 'index']);

        // Admin Dashboard & Dash Metrics
        Route::get('/admin/dashboard-hub', [AdminDashboardController::class, 'getDashboardData']);
        Route::get('/admin/dashboard/metrics', [AdminDashboardController::class, 'metrics']); 
        Route::get('/admin/financials', [AdminDashboardController::class, 'metrics']); 
        
        // Disputes & Escalations
        Route::get('/admin/disputes', [AdminDashboardController::class, 'disputes']); 
        Route::post('/admin/disputes/{id}/resolve', [AdminDashboardController::class, 'resolveDispute']); 

        // Escrow Oversight
        Route::get('/admin/escrows', [AdminEscrowController::class, 'index']);
        Route::post('/admin/escrows/disputes/{id}/resolve', [AdminEscrowController::class, 'resolveDispute']);

        // Transaction Management
        Route::get('/admin/transactions', [AdminTransactionController::class, 'index']);
        Route::get('/admin/transactions/export', [AdminTransactionController::class, 'export']);
        Route::patch('/admin/transactions/{payment}/status', [AdminTransactionController::class, 'updateStatus']);

        // KYC / Secure Document Approval Queue
        Route::patch('/vault/documents/{document}/status', [VaultDocumentController::class, 'updateStatus']);

        // Property Admin Controls (Verification & Purges)
        Route::get('/admin/properties', [AdminPropertyController::class, 'index']);
        Route::patch('/admin/properties/{property}/status', [AdminPropertyController::class, 'updateStatus']);
        Route::delete('/admin/properties/{id}', [AdminPropertyController::class, 'destroy']);
        Route::delete('/admin/properties/{id}/permanent', [AdminPropertyController::class, 'forceDestroy']);
    });
});

// =========================================================================
// 4. M2M INTERNAL (Agent Service via Python/AI)
// =========================================================================
Route::prefix('internal/ai')
    ->middleware(VerifyM2MToken::class)
    ->group(function () {
        // Context Queries
        Route::get('/agencies/{agencyId}/leads', [InternalAiController::class, 'getLeads']);
        Route::get('/agencies/{agencyId}/properties', [AgentInventoryController::class, 'getProperties']);

        // Action Executions
        Route::post('/alerts/property-matches', [AlertController::class, 'storePropertyMatches']);
        Route::post('/properties/scraped', [PropertyController::class, 'storeScrapedProperty']);
    });