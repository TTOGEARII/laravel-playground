<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 픽업 배너(모집중 학생) — 게임별 현재/예정(미래시) 모집.
 * SchaleDB config(Regions[Global]=현재, Regions[Jp]=미래시)에서 동기화한다.
 * (게임, external_key)가 동일성 키 — 멱등 upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('external_key', 120)->comment('소스 상 고유 식별자(scope+종류+시작시각 등)');
            $table->string('scope', 20)->default('current')->comment('current(현재) | forecast(미래시)');
            $table->string('kind', 30)->default('character')->comment('character/weapon/light_cone 등');
            $table->string('title', 200)->nullable();
            $table->json('featured')->nullable()->comment('픽업 대상 [{external_key,name,image,rarity}]');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('source', 40)->comment('수집 소스(schaledb 등)');
            $table->timestamps();

            $table->unique(['subculture_game_id', 'external_key'], 'uniq_sb_game_extkey');
            $table->index(['subculture_game_id', 'scope', 'starts_at'], 'idx_sb_game_scope_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_banners');
    }
};
