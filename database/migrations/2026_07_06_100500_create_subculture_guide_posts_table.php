<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 커뮤니티 공략글 메타(디씨 개념글 · 아카 추천글). 제목/링크/작성일/조회수만 저장하고
 * 본문은 파싱하지 않는다. 제목이 보스명·레이드 키워드와 매칭되면 raid 에 연결한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_guide_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->foreignId('subculture_raid_id')->nullable()->constrained('subculture_raids')->nullOnDelete()
                ->comment('제목 키워드로 매칭된 레이드(없으면 일반 공략)');
            $table->string('source', 10)->comment('dc | arca');
            $table->string('external_id', 40)->comment('글번호(갤러리/채널 내 고유)');
            $table->string('title', 255);
            $table->string('url', 500);
            $table->dateTime('posted_at')->nullable();
            $table->unsignedInteger('views')->default(0)->comment('조회수');
            $table->string('matched_keyword', 80)->nullable()->comment('레이드 매칭에 쓰인 키워드(보스명 등)');
            $table->timestamps();

            $table->unique(['subculture_game_id', 'source', 'external_id'], 'uniq_sgp_game_src_ext');
            $table->index(['subculture_game_id', 'posted_at'], 'idx_sgp_game_posted');
            $table->index('subculture_raid_id', 'idx_sgp_raid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_guide_posts');
    }
};
