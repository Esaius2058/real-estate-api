<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            
            $table->string('user_id')->nullable(); // The Client ID (e.g. USR-3322)
            $table->string('type'); // e.g., national_id_front, contract
            $table->string('s3_path'); // The raw Supabase/AWS path
            $table->string('status')->default('pending_review'); // pending_review, approved, rejected
            $table->text('notes')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_documents');
    }
};