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
        // 1. Add the column as nullable initially
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->after('agent_id')->nullable();
        });

        // 2. (Optional) Populate existing leads with a default valid property ID
        // If you don't care about existing leads' property association, 
        // you can point them to a 'dummy' property or just leave them null if you don't add the constraint yet.
        // DB::table('leads')->update(['property_id' => 1]); // Example: update existing leads to property #1

        // 3. Add the foreign key constraint
        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn('property_id');
        });
    }
};
