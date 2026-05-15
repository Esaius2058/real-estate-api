<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_documents', function (Blueprint $table) {

            $table->id();

           

            $table->foreignId('agency_id')
                ->constrained()
                ->cascadeOnDelete();

            

            $table->morphs('documentable');

            

            $table->enum('document_type', [
                'title_deed',
                'national_id',
                'kra_pin'
            ]);

            $table->string('s3_private_path');

            $table->enum('verification_status', [
                'pending_review',
                'verified',
                'rejected'
            ])->default('pending_review');

            $table->timestamps();

            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_documents');
    }
};