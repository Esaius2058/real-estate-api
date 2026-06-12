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
    Schema::create('chat_messages', function (Blueprint $table) {
        $table->id();
        $table->string('session_id'); // To group messages by user/session
        $table->string('role');       // 'user' or 'model'
        $table->text('content');      // The actual message
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
