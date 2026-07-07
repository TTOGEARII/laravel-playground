<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 내 풀 조합의 미보유 캐릭터에 사용자가 직접 지정한 대체 캐릭터 매핑.
 * 캐릭터는 external_key 문자열로 참조한다 — 캐릭터 마스터 재수집(행 교체)에도
 * 매핑이 살아남고, 게스트 localStorage 계약(external_key 기반)과 대칭.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_user_substitutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('character_key', 100)->comment('미보유(원) 캐릭터 external_key');
            $table->string('substitute_key', 100)->comment('대신 쓸 보유 캐릭터 external_key');
            $table->timestamps();

            // 미보유 캐릭터당 대체 1명(바꾸면 upsert)
            $table->unique(['user_id', 'subculture_game_id', 'character_key'], 'uniq_sus_user_game_char');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_user_substitutes');
    }
};
