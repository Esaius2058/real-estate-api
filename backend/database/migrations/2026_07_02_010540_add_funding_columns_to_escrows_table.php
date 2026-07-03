<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escrows', function (Blueprint $table) {
            $table->decimal('total_paid', 15, 2)->default(0)->after('amount');
            $table->decimal('remaining', 15, 2)->default(0)->after('total_paid');
            $table->boolean('is_fully_funded')->default(false)->after('remaining');
        });
    }

    public function down(): void
    {
        Schema::table('escrows', function (Blueprint $table) {
            $table->dropColumn(['total_paid', 'remaining', 'is_fully_funded']);
        });
    }
};