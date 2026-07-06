<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 로그인 사용자의 캐릭터 풀(보유 여부 + 성장도).
 * 비로그인 사용자는 클라이언트 localStorage 에 저장하므로 이 테이블을 쓰지 않는다
 * (리딤코드 redemptions 와 동일 패턴). growth JSON 의 스키마는
 * config subculture-game-info.raids.growth_fields 의 게임별 정의를 따른다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_user_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subculture_character_id')->constrained('subculture_characters')->cascadeOnDelete();
            $table->boolean('owned_flg')->default(true)->comment('보유 여부');
            $table->json('growth')->nullable()->comment('성장도(게임별 growth_fields 정의를 따르는 자유 스키마)');
            $table->timestamps();

            $table->unique(['user_id', 'subculture_character_id'], 'uniq_suc_user_char');
            $table->index('user_id', 'idx_suc_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_user_characters');
    }
};
