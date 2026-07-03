<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to establish the core auditing ledger.
     */
    public function up(): void
    {
        // 1. Core Payments Log (For both Subscriptions and Escrow Funding)
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    
    // Add the escrow relationship directly here as nullable
    $table->foreignId('escrow_id')->nullable()->constrained()->onDelete('cascade');
    
    $table->bigInteger('amount'); // Stored securely in cents/minor units
    $table->string('currency')->default('KES');
    $table->string('payment_method'); // mpesa, card
    $table->string('transaction_reference')->unique();
    $table->string('status')->default('pending'); // pending, success, failed
    $table->timestamps();
});
        // 2. Escrow State Machine Events Tracker
        Schema::create('escrow_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('escrow_id'); // Pointers to your main escrows table
            $table->string('action'); // funded, milestone_approved, dispute_raised, released
            $table->string('actor'); // buyer, seller, system, admin
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Assuming your main escrows table exists from the previous setup
            $table->foreign('escrow_id')->references('id')->on('escrows')->onDelete('cascade');
        });

        // 3. Refunds Registry
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->bigInteger('amount'); // Stored in cents
            $table->string('reason');
            $table->string('status')->default('pending'); // pending, processed, failed
            $table->timestamps();
        });

        // 4. Raw Third-Party Transaction JSON Payloads (Crucial for Financial Audits)
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->onDelete('set null');
            $table->string('event'); // e.g., 'charge.success', 'payment.failed'
            $table->json('payload'); // Full, raw webhook/API response signature
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_logs');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('escrow_events');
        Schema::dropIfExists('payments');
    }
};