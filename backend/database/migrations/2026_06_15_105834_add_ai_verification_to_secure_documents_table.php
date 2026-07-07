<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secure_documents', function (Blueprint $table) {
            // "pending", "ai_verified", "ai_flagged"
            $table->string('ai_verification_status')->default('pending')->after('status'); 
            $table->decimal('ai_confidence_score', 5, 2)->nullable()->after('ai_verification_status');
            $table->text('ai_reasoning')->nullable()->after('ai_confidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('secure_documents', function (Blueprint $table) {
            $table->dropColumn(['ai_verification_status', 'ai_confidence_score', 'ai_reasoning']);
        });
    }
};