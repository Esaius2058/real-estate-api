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
            // Add missing relationship and metadata columns
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('agency_id');
            $table->text('notes')->nullable()->after('s3_private_path');
            
            // Add OCR columns (Notice we are correctly targeting verification_status now)
            $table->longText('extracted_text')->nullable()->after('verification_status');
            $table->json('ml_data')->nullable()->after('extracted_text');
            
            // Modify the ENUM to accommodate your React frontend options
            DB::statement("ALTER TABLE secure_documents MODIFY COLUMN document_type ENUM('title_deed', 'national_id', 'national_id_front', 'national_id_back', 'passport', 'kra_pin', 'selfie_verification', 'proof_of_address', 'contract') NOT NULL");
        });
    }

    public function down(): void
    {
        Schema::table('secure_documents', function (Blueprint $table) {
            $table->dropColumn(['extracted_text', 'ml_data']);
        });
    }
};
