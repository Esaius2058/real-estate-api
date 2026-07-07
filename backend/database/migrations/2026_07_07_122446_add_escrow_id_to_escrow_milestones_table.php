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
        Schema::table('escrow_milestones', function (Blueprint $table) {
            // Add the column after the primary key, set up the foreign key constraint, and cascade deletes
            $table->foreignId('escrow_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escrow_milestones', function (Blueprint $table) {
            $table->dropForeign(['escrow_id']);
            $table->dropColumn('escrow_id');
        });
    }
};