<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'subscription_id')) {
                $table->unsignedBigInteger('subscription_id')->nullable()->after('escrow_id');
            }
            if (!Schema::hasColumn('payments', 'checkout_request_id')) {
                $table->string('checkout_request_id')->nullable()->after('transaction_reference');
            }
            if (!Schema::hasColumn('payments', 'merchant_request_id')) {
                $table->string('merchant_request_id')->nullable()->after('checkout_request_id');
            }
            if (!Schema::hasColumn('payments', 'receipt_number')) {
                $table->string('receipt_number')->nullable()->after('merchant_request_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['subscription_id', 'checkout_request_id', 'merchant_request_id', 'receipt_number'] as $col) {
                if (Schema::hasColumn('payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};