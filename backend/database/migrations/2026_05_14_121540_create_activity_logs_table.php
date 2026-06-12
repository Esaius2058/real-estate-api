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

            

            $table->foreignId('agency_id')
                ->constrained()
                ->cascadeOnDelete();

            

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();


            $table->string('action');

            $table->string('ip_address');

            $table->timestamps();

            

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