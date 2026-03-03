<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('대화 세션 회원 ID');
            $table->foreignId('chat_character_id')->constrained('chat_characters')->cascadeOnDelete();
            $table->text('conversation_summary')->nullable()->comment('대화 요약');
            $table->unsignedBigInteger('summarized_until_message_id')->nullable()->comment('요약에 포함된 마지막 메시지 ID');
            $table->timestamps();

            $table->index('user_id');
            $table->index('chat_character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
