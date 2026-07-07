<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up()
{
    Schema::table('users', function (Blueprint $table) {
        $table->boolean('access')->default(true); // Default to 'true' (enabled)
    });
}

public function down()
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('access');
    });
}
};
