<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the table first to start completely clean
        Schema::dropIfExists('subscriptions');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('subscribable_type');
            $table->unsignedBigInteger('subscribable_id');
            
            // MODERN FIX: Foolproof inline foreign ID pairing
            $table->foreignId('tier_id')
                  ->constrained('subscription_tiers')
                  ->cascadeOnDelete();
            
            $table->string('status');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('payment_provider')->default('paystack');
            $table->string('provider_subscription_id')->nullable();
            $table->timestamps();

            // Index polymorphic columns
            $table->index(['subscribable_type', 'subscribable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};