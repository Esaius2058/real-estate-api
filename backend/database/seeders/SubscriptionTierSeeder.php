<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('subscription_tiers', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_tiers', 'monthly_price')) {
                $table->decimal('monthly_price', 10, 2)->after('slug');
            }
            if (!Schema::hasColumn('subscription_tiers', 'yearly_price')) {
                $table->decimal('yearly_price', 10, 2)->nullable()->after('monthly_price');
            }
            if (!Schema::hasColumn('subscription_tiers', 'max_properties')) {
                $table->integer('max_properties')->default(5)->after('features');
            }
            if (!Schema::hasColumn('subscription_tiers', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('max_properties');
            }
        });
    }
    public function down()
    {
        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->dropColumn(['monthly_price', 'yearly_price', 'max_properties', 'is_active']);
        });
    }
};