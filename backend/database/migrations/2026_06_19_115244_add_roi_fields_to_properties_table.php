<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Distinguish between Apartment, Maisonette, Commercial, Land, etc.
            $table->string('type')->default('Apartment')->after('title'); 
            
            // Monthly service charge or HOA fees heavily impact net yield
            $table->decimal('service_charge', 10, 2)->nullable()->after('price'); 
            
            // Optional: If the property is already tenanted, what is the current rent?
            $table->decimal('current_rent', 10, 2)->nullable()->after('service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['type', 'service_charge', 'current_rent']);
        });
    }
};