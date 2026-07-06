<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the existing payments.escrow_id column so an M-Pesa STK push
 * payment can be tied back to the Subscription it's paying for, the same
 * way escrow deposit payments are tied to an escrow. Needed for the
 * Daraja callback to know which subscription to activate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'subscription_id')) {
                $table->foreignId('subscription_id')->nullable()->after('escrow_id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });
    }
};