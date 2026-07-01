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
        // Only add the column if it doesn't exist
        if (!Schema::hasColumn('leads', 'property_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->unsignedBigInteger('property_id')->after('agent_id')->nullable();
            });
        }

        // Only add the foreign key if it's not already there
        // Note: This check is harder in Laravel, so we wrap it in a try-catch
        try {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreign('property_id')
                    ->references('id')
                    ->on('properties')
                    ->onDelete('cascade');
            });
        } catch (\Exception $e) {
            // Foreign key likely already exists
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn('property_id');
        });
    }
};
