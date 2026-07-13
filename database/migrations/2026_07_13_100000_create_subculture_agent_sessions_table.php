<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 서브컬쳐 AI 에이전트 대화 세션. 로그인 없이도 쓸 수 있어 user_id 는 nullable.
 * 페르소나는 프리셋(preset) 또는 내 챗봇 캐릭터(character) 중 하나.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_agent_sessions', function (Blueprint $table) {
            $table->id();
            // 외부 노출 키 — 순차 id 로 남의 세션(특히 비로그인)을 추측 열람하지 못하게 UUID 로 조회한다
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('persona_kind', 20)->default('preset')->comment('preset|character');
            $table->string('persona_ref', 100)->nullable()->comment('프리셋 키 또는 chat_character id');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_agent_sessions');
    }
};
