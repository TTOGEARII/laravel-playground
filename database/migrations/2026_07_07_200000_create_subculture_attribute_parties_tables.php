<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 속성(성격)별 추천 조합 — 트릭컬처럼 속성 시너지가 편성의 축인 게임용.
 * 소스: 팀 매니저 큐레이션(curated) + 트릭컬 레코드 시즌 실측 파생(usage).
 * 크롤 sync 때 소스 단위로 갈아끼운다(레이드 편성과 동일 원칙).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_attribute_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('attribute', 20)->comment('성격 코드(Jolly/Mad/Cool/Naive/Gloomy) — 캐릭터 traits.personality 와 동일 표기');
            $table->string('kind', 20)->comment('curated=큐레이션 추천 / usage=시즌 실측 파생');
            $table->string('source', 40)->comment('출처(team-manager/trickcalrecord)');
            $table->string('title')->comment('표시 제목(예: 추천 편성, 실측 인기 · 프론티어 시즌18)');
            $table->string('source_url', 500)->nullable();
            $table->string('period', 40)->nullable()->comment('실측 시즌 기간(표시용)');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['subculture_game_id', 'attribute'], 'idx_sap_game_attr');
        });

        Schema::create('subculture_attribute_party_members', function (Blueprint $table) {
            $table->id();
            // 기본 생성 FK 이름이 MySQL 식별자 64자 제한을 넘어 짧은 이름을 명시한다
            $table->foreignId('attribute_party_id')
                ->constrained(table: 'subculture_attribute_parties', indexName: 'fk_sapm_party')
                ->cascadeOnDelete();
            $table->foreignId('subculture_character_id')
                ->constrained(table: 'subculture_characters', indexName: 'fk_sapm_character')
                ->cascadeOnDelete();
            $table->string('position', 10)->nullable()->comment('front/middle/back');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->json('meta')->nullable()->comment('aside(사이드 페어링 이름), usage_pct(시즌 사용률) 등');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_attribute_party_members');
        Schema::dropIfExists('subculture_attribute_parties');
    }
};
