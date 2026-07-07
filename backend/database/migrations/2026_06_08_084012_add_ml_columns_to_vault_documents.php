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
        Schema::table('secure_documents', function (Blueprint $table) {
            // FIX: Target the 'type' column, not 'document_type'
            $table->enum('type', [
                'title_deed', 
                'national_id', 
                'national_id_front', 
                'national_id_back', 
                'passport', 
                'kra_pin', 
                'selfie_verification', 
                'proof_of_address', 
                'contract'
            ])->change();

            // Assuming this migration also adds the ML extraction data columns:
            if (!Schema::hasColumn('secure_documents', 'extracted_text')) {
                $table->longText('extracted_text')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('secure_documents', 'ml_data')) {
                $table->json('ml_data')->nullable()->after('extracted_text');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('secure_documents', function (Blueprint $table) {
            // Revert 'type' back to a standard string if rolled back
            $table->string('type')->change();

            if (Schema::hasColumn('secure_documents', 'extracted_text')) {
                $table->dropColumn('extracted_text');
            }
            if (Schema::hasColumn('secure_documents', 'ml_data')) {
                $table->dropColumn('ml_data');
            }
        });
    }
};