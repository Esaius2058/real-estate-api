<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Subscription Tiers
        Schema::create('subscription_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); 
            $table->string('slug')->unique();
            $table->unsignedBigInteger('price_monthly'); // Stored in cents/minor units
            $table->integer('max_properties');
            $table->json('features');
            $table->timestamps();
        });

        // 2. User Active Subscriptions Link
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_tier_id')->constrained();
            $table->string('status'); // active, past_due, canceled
            $table->string('gateway_reference')->nullable(); // Mastercard transaction ID
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        // 3. Escrow Arbitrage Disputes
        Schema::create('escrow_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->onDelete('cascade');
            $table->foreignId('raised_by_id')->constrained('users');
            $table->text('reason');
            $table->enum('status', ['open', 'under_review', 'resolved'])->default('open');
            $table->enum('resolution', ['pending', 'refunded_to_buyer', 'released_to_seller'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_disputes');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_tiers');
    }
};