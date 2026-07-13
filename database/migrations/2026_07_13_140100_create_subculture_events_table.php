<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 진행중/예정 컨텐츠(이벤트·스토리·레이드 예고 등) — 게임별 현재/미래시.
 * SchaleDB config(Regions[Global]=현재, Regions[Jp]=미래시)에서 동기화한다.
 * 미래시 타임라인은 이 테이블 + subculture_banners 의 forecast 행을 합쳐 만든다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('external_key', 120)->comment('소스 상 고유 식별자');
            $table->string('scope', 20)->default('current')->comment('current(현재) | forecast(미래시)');
            $table->string('kind', 30)->default('event')->comment('event/raid/story/maintenance 등');
            $table->string('title', 200);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('source', 40)->comment('수집 소스(schaledb 등)');
            $table->timestamps();

            $table->unique(['subculture_game_id', 'external_key'], 'uniq_se_game_extkey');
            $table->index(['subculture_game_id', 'scope', 'starts_at'], 'idx_se_game_scope_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_events');
    }
};
