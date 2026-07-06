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

// Financial Engine Imports
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\SubscriptionController;
use App\Http\Controllers\Api\v1\EscrowController;
use App\Http\Controllers\Api\v1\PayoutController;

use App\Http\Middleware\VerifyM2MToken;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ── PUBLIC ────────────────────────────────────────────────────────────────

Route::post('/login',    [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);

// Password recovery
Route::post('/password/forgot', [PasswordController::class, 'sendResetCode']);
Route::post('/password/reset',  [PasswordController::class, 'resetPassword']);

// Public property reads
Route::get('/properties',                          [PropertyController::class, 'index']);
Route::get('/properties/{property}',               [PropertyController::class, 'show']);
Route::post('/properties/shares/sign-images',      [PropertyController::class, 'generatePublicSignedUrls']);

// Public lead creation (checkout form)
Route::apiResource('/leads', LeadController::class)->only(['store']);

// Chat (Trevor's branch had this public — keep as-is until auth is added)
Route::post('/chat', [ChatController::class, 'sendMessage']);

// Financial webhooks — no auth, verified by signature
Route::post('/payments/callback',  [PaymentController::class, 'callback']);
Route::post('/paystack/webhook',   [SubscriptionController::class, 'webhook']);
Route::get('/paystack/callback',   [PaymentController::class, 'verifyPaystack']);

Route::prefix('payouts')->group(function () {
    Route::post('/result',  [PayoutController::class, 'handleMpesaResult']);
    Route::post('/timeout', [PayoutController::class, 'handleMpesaResult']);
});


// ── PROTECTED (Sanctum) ───────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth & profile
    Route::post('/logout',               [AuthenticationController::class, 'logout']);
    Route::get('/me',                    [AuthenticationController::class, 'me']);
    Route::post('/me',                   [AuthenticationController::class, 'updateProfile']);
    Route::post('/password/update',      [PasswordController::class, 'update']);

    // Notifications
    Route::get('/me/notifications',      [AlertController::class, 'index']);
    Route::post('/me/notifications/read',[AlertController::class, 'markAsRead']);

    // 2FA settings
    Route::prefix('settings/2fa')->group(function () {
        Route::post('/enable',  [\App\Http\Controllers\Api\v1\TwoFactorController::class, 'enable']);
        Route::post('/disable', [\App\Http\Controllers\Api\v1\TwoFactorController::class, 'disable']);
        Route::post('/verify',  [\App\Http\Controllers\Api\v1\TwoFactorController::class, 'verify']);
    });

    // Dashboard
    Route::get('/dashboard/summary', [DashboardController::class, 'index']);

    // Leads (protected reads/updates)
    Route::apiResource('/leads', LeadController::class)->except(['store']);

    // Vault
    Route::prefix('vault')->group(function () {
        Route::get('/documents',                  [VaultDocumentController::class, 'index']);
        Route::post('/documents',                 [VaultDocumentController::class, 'store']);
        Route::patch('/documents/{id}/status',    [VaultDocumentController::class, 'updateStatus']);
        Route::delete('/documents/{id}',          [VaultDocumentController::class, 'destroy']);
        Route::post('/presigned-upload-url',      [VaultDocumentController::class, 'presignedUploadUrl']);
        Route::post('/initialize-workspace',      [AgencyController::class, 'store']);
    });

    // Agency
    Route::prefix('agency')->group(function () {
        Route::post('/join',       [AgencyController::class, 'join']);
        Route::get('/',            [AgencyController::class, 'show']);
        Route::put('/{agency}',    [AgencyController::class, 'update']);
    });

    // Subscriptions
    Route::prefix('subscriptions')->group(function () {
        Route::get('/tiers',    [SubscriptionController::class, 'getTiers']);
        Route::post('/subscribe',[SubscriptionController::class, 'subscribe']);
        Route::get('/current',  [SubscriptionController::class, 'mySubscription']);
    });

    // Payments
    Route::prefix('payments')->group(function () {
        Route::post('/stk-push',                [PaymentController::class, 'stkPush']);
        Route::get('/status/{checkoutRequestId}',[PaymentController::class, 'checkStatus']);
        Route::post('/paystack/initialize',     [PaymentController::class, 'initializePaystack']);
        Route::get('/history',                  [PaymentController::class, 'history']);
    });

    // Escrow
    Route::prefix('escrows')->group(function () {
        Route::get('/',                          [EscrowController::class, 'index']);
        Route::post('/',                         [EscrowController::class, 'store']);
        Route::get('/{id}',                      [EscrowController::class, 'show']);
        Route::post('/{id}/milestones',          [EscrowController::class, 'addMilestone']);
        Route::post('/milestones/{id}/approve',  [EscrowController::class, 'approveMilestone']);
        Route::post('/{id}/dispute',             [EscrowController::class, 'raiseDispute']);
    });

    // Payouts
    Route::post('/payouts/milestone/{id}/release', [PayoutController::class, 'releaseMilestonePayout']);

    // AI endpoints
    Route::post('/matches/draft',         [AlertController::class, 'draftProposal']);
    Route::get('/properties/comps',       [PropertyController::class, 'getComps']);
    Route::post('/properties/predict-roi',[PropertyController::class, 'predictROI']);


    // ── AGENT + ADMIN ─────────────────────────────────────────────────────
    Route::middleware('role:agent,admin')->group(function () {

        Route::get('/agency',                              [AgencyController::class, 'show']);
        Route::get('/agent/properties',                    [PropertyController::class, 'agencyIndex']);
        Route::get('/agent/properties/{property}',         [PropertyController::class, 'show']);

        Route::post('/properties',                         [PropertyController::class, 'store']);
        Route::put('/properties/{property}',               [PropertyController::class, 'update']);
        Route::delete('/properties/{property}',            [PropertyController::class, 'destroy']);
        Route::post('/properties/{property}/images',       [PropertyController::class, 'attachImage']);
        Route::post('/properties/marketing/generate',      [PropertyController::class, 'generateMarketingCopy']);

        Route::patch('/leads/{lead}/kanban',               [LeadKanbanController::class, 'update']);

        Route::post('/vault/presigned-url',                [VaultDocumentController::class, 'generateUploadUrl']);
    });


    // ── ADMIN ONLY ────────────────────────────────────────────────────────
    Route::middleware('role:admin')->group(function () {

        // Agency
        Route::put('/agency/{agency}', [AgencyController::class, 'update']);

        // Agent management
        Route::apiResource('/agents', AgentController::class)->except(['create', 'edit', 'show']);

        // User management (Trevor's branch)
        Route::get('/admin/users',                 [AdminDashboardController::class, 'getUsers']);
        Route::post('/admin/users',                [UserController::class, 'store']);
        Route::delete('/admin/users/{id}',         [UserController::class, 'destroy']);
        Route::patch('/admin/users/{id}/access',   [UserController::class, 'updateAccess']);

        // Activity logs & sessions (Trevor's branch)
        Route::get('/admin/logs',                  [LogController::class, 'index']);

        // Dashboard & disputes
        Route::get('/admin/dashboard-hub',         [AdminDashboardController::class, 'getDashboardData']);
        Route::get('/admin/disputes',              [AdminDashboardController::class, 'disputes']);
        Route::post('/admin/disputes/{id}/resolve',[AdminDashboardController::class, 'resolveDispute']);

        // KYC approval
        Route::patch('/vault/documents/{document}/status', [VaultDocumentController::class, 'updateStatus']);

        // Property admin controls
        Route::get('/admin/properties',                        [AdminPropertyController::class, 'index']);
        Route::patch('/admin/properties/{property}/status',    [AdminPropertyController::class, 'updateStatus']);
        Route::delete('/admin/properties/{id}',                [AdminPropertyController::class, 'destroy']);
        Route::delete('/admin/properties/{id}/permanent',      [AdminPropertyController::class, 'forceDestroy']);
    });

});


// ── M2M INTERNAL (Agent Service) ─────────────────────────────────────────

Route::prefix('internal/ai')
    ->middleware(VerifyM2MToken::class)
    ->group(function () {

        Route::get('/agencies/{agencyId}/leads', function ($agencyId) {
            $leads = \App\Models\Lead::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('kanban_stage', 'new')
                ->get();
            return response()->json(['data' => $leads]);
        });

        Route::get('/agencies/{agencyId}/properties', function ($agencyId) {
            $properties = \App\Models\Property::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereIn('status', ['active', 'active_listing'])
                ->get();
            return response()->json(['data' => $properties]);
        });

        Route::post('/alerts/property-matches', [AlertController::class, 'storePropertyMatches']);
    });