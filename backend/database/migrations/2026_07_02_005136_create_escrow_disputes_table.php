<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
 public function up(): void
{
    if (!Schema::hasTable('escrow_disputes')) {
        Schema::create('escrow_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained('escrows')->onDelete('cascade');
            $table->foreignId('raised_by_user_id')->constrained('users')->onDelete('cascade');
            $table->string('reason');
            $table->text('description');
            $table->enum('status', ['pending', 'under_review', 'resolved', 'dismissed'])->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }
}
    public function down(): void
    {
        Schema::dropIfExists('escrow_disputes');
    }
};