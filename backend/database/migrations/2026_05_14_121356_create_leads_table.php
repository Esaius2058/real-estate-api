<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            
            // Add the missing agent_id column. It references the users table.
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->decimal('value', 15, 2)->nullable();
            
            $table->enum('kanban_stage', [
                'new',
                'contacted',
                'showing',
                'offer',
                'escrow',
                'closed',
                'lost'
            ])->default('new');

            $table->timestamps();

            // Indexes for faster lookups on your Kanban board
            $table->index('agency_id');
            $table->index('agent_id');
            $table->index('kanban_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};