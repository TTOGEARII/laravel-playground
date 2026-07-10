<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이벤트 챌린지 공략(블아) — 아카 '올인원' 글의 챌린지 섹션을 스테이지 단위로 저장.
 * 스테이지별 클리어 조건·공략 요약·유튜브 영상·언급 캐릭터를 담는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_event_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('event_key', 50)->comment('소스 글 식별자(아카 글 ID)');
            $table->string('event_name');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('stage_label', 30)->comment('Challenge 01 / Challenge EX');
            $table->string('stage_name')->nullable()->comment('맵 이름');
            $table->string('clear_condition')->nullable()->comment('클리어 조건(90초 이내 등)');
            $table->text('summary')->nullable()->comment('공략 요약(본문 발췌)');
            $table->string('video_url', 500)->nullable()->comment('유튜브 공략 영상');
            $table->json('mentioned')->nullable()->comment('본문에 언급된 캐릭터 이름들');
            $table->string('source_url', 500);
            $table->timestamps();

            $table->unique(['subculture_game_id', 'event_key', 'stage_label'], 'uq_event_challenge_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_event_challenges');
    }
};
