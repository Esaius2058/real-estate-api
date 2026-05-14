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
            LEAD DETAILS
            */

            $table->string('name');

            $table->enum('kanban_stage', [
                'new_lead',
                'viewing',
                'negotiating',
                'closed'
            ])->default('new_lead');

            $table->string('desired_location');

            $table->decimal('max_budget', 15, 2);

            $table->timestamps();

            /*
            INDEXES
            */

            $table->index('agency_id');

            $table->index('user_id');

            $table->index('desired_location');

            $table->index('kanban_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};