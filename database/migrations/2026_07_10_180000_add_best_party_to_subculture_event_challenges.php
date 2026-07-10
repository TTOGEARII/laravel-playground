<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이벤트 챌린지 추천 조합 — 공략 재료(올인원 요약·영상 제목·언급 캐릭터)를 Gemini 로
 * 정리해 뽑은 스테이지별 베스트 조합. [{name, key}] 형태(닫힌 어휘 검증 후 저장).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subculture_event_challenges', function (Blueprint $table) {
            $table->json('best_party')->nullable()->after('extra_videos')->comment('추천 조합 [{name, key}]');
        });
    }

    public function down(): void
    {
        Schema::table('subculture_event_challenges', function (Blueprint $table) {
            $table->dropColumn('best_party');
        });
    }
};
