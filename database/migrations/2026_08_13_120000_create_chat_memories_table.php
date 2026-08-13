<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('chat_character_id')->nullable()->comment('캐릭터 ID (세션 기준)');
            $table->string('kind', 30)->default('summary')->comment('기억 종류 (summary 등)');
            $table->text('content')->comment('기억 문장(요약 등)');
            // 임베딩 벡터를 JSON 배열로 보관 — MySQL 벡터 타입 대신(sqlite 테스트 호환), 코사인 유사도는 PHP에서 계산.
            $table->json('embedding')->nullable()->comment('임베딩 벡터(float 배열)');
            $table->timestamps();

            $table->index(['chat_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_memories');
    }
};
