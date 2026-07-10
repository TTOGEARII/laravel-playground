<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이벤트 챌린지 보조 영상 — 유튜브 검색·디시 챌린지 글에서 수집한 스테이지별 관련 영상 목록.
 * [{url, title, source(youtube|dc)}] 형태.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subculture_event_challenges', function (Blueprint $table) {
            $table->json('extra_videos')->nullable()->after('video_url')->comment('보조 공략 영상 목록');
        });
    }

    public function down(): void
    {
        Schema::table('subculture_event_challenges', function (Blueprint $table) {
            $table->dropColumn('extra_videos');
        });
    }
};
