<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {

            $table->id();

            /*
            MULTITENANCY
            */

            $table->foreignId('agency_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            USER RESPONSIBLE
            */

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            AUDIT DATA
            */

            $table->string('action');

            $table->string('ip_address');

            $table->timestamps();

            /*
            INDEXES
            */

            $table->index('agency_id');

            $table->index('user_id');

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};