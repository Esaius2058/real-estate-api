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
        Schema::table('payments', function (Blueprint $table) {
            // Appends agency_id safely right after the primary id column
            if (!Schema::hasColumn('payments', 'agency_id')) {
                $table->foreignId('agency_id')->default(1)->after('id')->constrained();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payments_agency_id_foreign']); // Drops the foreign key constraint safely
            $table->dropColumn('agency_id');
        });
    }
}; 