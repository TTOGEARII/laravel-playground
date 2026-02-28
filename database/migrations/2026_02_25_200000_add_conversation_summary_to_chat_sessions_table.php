<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->text('conversation_summary')->nullable()->after('chat_character_id');
            $table->unsignedBigInteger('summarized_until_message_id')->nullable()->after('conversation_summary');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['conversation_summary', 'summarized_until_message_id']);
        });
    }
};
