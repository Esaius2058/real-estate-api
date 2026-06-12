<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tell MySQL to ignore relational constraints temporarily
        Schema::disableForeignKeyConstraints();
        
        Schema::dropIfExists('subscription_tiers');
        
        // 2. Turn protection rules back on immediately
        Schema::enableForeignKeyConstraints();

        Schema::create('subscription_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('yearly_price', 10, 2)->nullable();
            $table->json('features');
            $table->integer('max_properties')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('subscription_tiers');
        Schema::enableForeignKeyConstraints();
    }
};