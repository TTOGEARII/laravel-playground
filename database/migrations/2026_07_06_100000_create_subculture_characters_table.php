<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 게임별 캐릭터 마스터. 서드파티 사이트(몰루로그/letsdoro/triple-lab/BD2DB)를
 * Playwright 사이드카로 크롤해 시드하며, (게임, external_key)가 동일성 키다.
 * 소스에서 사라진 캐릭터는 삭제하지 않고 active_flg 로 소프트 비활성한다
 * (user_characters 등 사용자 데이터 FK 보존).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('external_key', 80)->comment('크롤 소스 상 고유 식별자(slug/id)');
            $table->string('name', 80)->comment('캐릭터명(한글)');
            $table->string('rarity', 20)->nullable()->comment('희귀도(블아 성급/니케 SSR 등 게임별 표기)');
            $table->json('traits')->nullable()->comment('게임별 속성(공격타입/버스트/포지션 등 자유 스키마)');
            $table->string('image_url', 500)->nullable()->comment('초상 이미지 URL(외부)');
            $table->string('source', 40)->comment('크롤 소스 키(mollulog/letsdoro/triplelab/souseha/manual)');
            $table->string('source_url', 500)->nullable();
            $table->boolean('active_flg')->default(true)->comment('노출 여부(소스에서 사라지면 소프트 비활성)');
            $table->timestamps();

            $table->unique(['subculture_game_id', 'external_key'], 'uniq_sc_game_extkey');
            $table->index(['subculture_game_id', 'name'], 'idx_sc_game_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_characters');
    }
};
