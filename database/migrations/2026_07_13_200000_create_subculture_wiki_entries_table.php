<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 게임 위키 항목 — 공식/팬 위키의 카테고리(메뉴)별 항목을 통째로 저장한다.
 * 소스: 호요랩 위키(젠존제 에이전트/W-엔진/Bangboo/디스크, 스타레일 캐릭터/광추/유물/한정 이벤트),
 *       wuthering.gg(명조 무기). (게임, source, menu_key, external_key)가 동일성 키.
 * filters = 목록 필터값(속성/등급 등, 그리드 배지), detail = 상세 페이지를 정규화한 섹션 목록.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_wiki_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('source', 40)->comment('hoyowiki | wutheringgg');
            $table->string('menu_key', 60)->comment('카테고리 키(메뉴 id 또는 슬러그)');
            $table->string('menu_label', 60)->comment('카테고리 한글 라벨(에이전트/광추/한정 이벤트 등)');
            $table->string('external_key', 120)->comment('소스 상 항목 id/슬러그');
            $table->string('name', 200);
            $table->string('icon_url', 500)->nullable();
            $table->json('filters')->nullable()->comment('목록 필터 배지 [{label, value}]');
            $table->json('detail')->nullable()->comment('상세 섹션 [{title, rows|paragraphs}] (정규화)');
            $table->timestamps();

            $table->unique(['subculture_game_id', 'source', 'menu_key', 'external_key'], 'uniq_swe_identity');
            $table->index(['subculture_game_id', 'menu_key'], 'idx_swe_game_menu');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_wiki_entries');
    }
};
