<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escrow_milestones', function (Blueprint $table) {
            if (!Schema::hasColumn('escrow_milestones', 'escrow_id')) {
                $table->foreignId('escrow_id')->constrained()->onDelete('cascade');
            }
            if (!Schema::hasColumn('escrow_milestones', 'name')) {
                $table->string('name');
            }
            if (!Schema::hasColumn('escrow_milestones', 'title')) {
                $table->string('title')->nullable();
            }
            if (!Schema::hasColumn('escrow_milestones', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('escrow_milestones', 'amount')) {
                $table->decimal('amount', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('escrow_milestones', 'due_date')) {
                $table->timestamp('due_date')->nullable();
            }
            if (!Schema::hasColumn('escrow_milestones', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('escrow_milestones', 'released_at')) {
                $table->timestamp('released_at')->nullable();
            }
            if (!Schema::hasColumn('escrow_milestones', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users');
            }
            if (!Schema::hasColumn('escrow_milestones', 'status')) {
                $table->enum('status', ['pending', 'approved', 'released', 'cancelled'])->default('pending');
            }
        });
    }

    public function down(): void
    {
        // No rollback — we're adding columns safely
    }
};