<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 서브컬쳐 게임 카탈로그 (리딤코드/게임정보의 기준 테이블).
 * 미니게임의 기존 `games` 테이블과 충돌하지 않도록 `subculture_games` 로 분리한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_games', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique()->comment('식별 슬러그 (genshin, starrail ...)');
            $table->string('name', 60)->comment('표시 이름(한글)');
            $table->string('publisher', 60)->nullable()->comment('개발/퍼블리셔');
            $table->string('icon', 16)->nullable()->comment('카드 아이콘(이모지)');
            $table->string('color', 30)->nullable()->comment('테마 색상 클래스');
            $table->string('redeem_url_template', 255)->nullable()->comment('원클릭 교환 직링크 템플릿({code} 치환), 인게임 전용이면 null');
            $table->string('redeem_note', 120)->nullable()->comment('교환 안내(예: 인게임 전용)');
            $table->string('region_default', 16)->default('global')->comment('기본 리전(한국 기준 코드 매핑용)');
            $table->unsignedSmallInteger('sort')->default(0)->comment('정렬 순서');
            $table->boolean('active_flg')->default(true)->comment('노출 여부');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_games');
    }
};
