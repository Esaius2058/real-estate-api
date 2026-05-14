<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {

            $table->id();

            /*
            MULTITENANCY
            */

            $table->foreignId('agency_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            ASSIGNED AGENT
            */

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            PROPERTY DETAILS
            */

            $table->string('title');

            $table->decimal('price', 15, 2);

            $table->string('location');

            $table->enum('status', [
                'active',
                'under_contract',
                'closed',
                'expired'
            ])->default('active');

            $table->date('contract_end_date')
                ->nullable();

            $table->timestamps();

            /*
            INDEXES
            */

            $table->index('agency_id');

            $table->index('user_id');

            $table->index('location');

            $table->index('status');

            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};