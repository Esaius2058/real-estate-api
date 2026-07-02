<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_logs', 'escrow_id')) {
                $table->unsignedBigInteger('escrow_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('transaction_logs', 'action')) {
                $table->string('action')->nullable()->after('escrow_id');
            }
            if (!Schema::hasColumn('transaction_logs', 'amount')) {
                $table->decimal('amount', 15, 2)->default(0)->after('action');
            }
            if (!Schema::hasColumn('transaction_logs', 'payload_snapshot')) {
                $table->longText('payload_snapshot')->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->dropColumn(['escrow_id', 'action', 'amount', 'payload_snapshot']);
        });
    }
};