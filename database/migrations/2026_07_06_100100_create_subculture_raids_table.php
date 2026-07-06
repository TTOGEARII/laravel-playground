<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 게임별 레이드(보스) 회차. (게임, external_key)가 동일성 키이며
 * 소스에 회차 식별자가 없으면 sync 서비스가 raid_type|boss|시작일 해시로 생성한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_raids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('external_key', 120)->comment('소스 회차 식별자(없으면 sync 가 해시 생성)');
            $table->string('name', 120)->comment('회차/이벤트명(예: 총력전 - 야외 비나)');
            $table->string('boss_name', 80)->nullable()->comment('보스명(공략글 키워드 매칭 키)');
            $table->string('raid_type', 40)->nullable()->comment('총력전/대결전(블아), 솔로/유니온 레이드(니케), 프론티어(트릭컬), 길드레이드(브더2)');
            $table->json('tags')->nullable()->comment('속성/지형/권장 정보(블아: terrain·armor_type 등)');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('source', 40)->comment('크롤 소스 키 또는 manual');
            $table->string('source_url', 500)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['subculture_game_id', 'external_key'], 'uniq_sr_game_extkey');
            $table->index(['subculture_game_id', 'starts_at'], 'idx_sr_game_start');
            $table->index('ends_at', 'idx_sr_ends');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_raids');
    }
};
