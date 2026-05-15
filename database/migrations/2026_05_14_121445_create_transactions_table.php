<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {

            $table->id();

            

            $table->foreignId('agency_id')
                ->constrained()
                ->cascadeOnDelete();


            $table->foreignId('property_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('lead_id')
                ->constrained()
                ->cascadeOnDelete();

          

            $table->decimal('amount', 15, 2);

            $table->enum('status', [
                'pending',
                'held_in_escrow',
                'released'
            ])->default('pending');

            $table->string('gateway_signature', 500);

            $table->timestamps();

            
            $table->index('agency_id');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};