<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('secure_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('secure_documents', 'uploaded_by')) {
                $table->unsignedBigInteger('uploaded_by')->nullable()->after('agency_id');
            }
            
            if (!Schema::hasColumn('secure_documents', 'notes')) {
                $table->text('notes')->nullable()->after('s3_private_path');
            }
            
            if (!Schema::hasColumn('secure_documents', 'extracted_text')) {
                $table->longText('extracted_text')->nullable()->after('verification_status');
            }
            
            if (!Schema::hasColumn('secure_documents', 'ml_data')) {
                $table->json('ml_data')->nullable()->after('extracted_text');
            }
        });

        // Modifying the ENUM is a raw SQL statement; run only on MySQL where MODIFY is supported
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE secure_documents MODIFY COLUMN type ENUM('title_deed', 'national_id', 'national_id_front', 'national_id_back', 'passport', 'kra_pin', 'selfie_verification', 'proof_of_address', 'contract') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
