<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::table('chat_messages', function (Blueprint $table) {
        // Add an index to session_id for faster lookups
        $table->index('session_id');
    });
}

public function down(): void
{
    Schema::table('chat_messages', function (Blueprint $table) {
        // Drop the index if we roll back
        $table->dropIndex(['session_id']);
    });
}
};
