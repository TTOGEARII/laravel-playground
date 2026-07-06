<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 레이드별 대체 캐릭터 관계("A가 없으면 B로 대체").
 * 커뮤니티 공략글 본문에서 Gemini 로 추출하거나(source=dc/arca/...) 수동 등록(source=manual)한다.
 * 미보유 상위 캐릭터를 내 캐릭터 풀로 메꿀 때 프론트가 이 관계를 사용한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_raid_substitutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raid_id')->constrained('subculture_raids')->cascadeOnDelete();
            $table->foreignId('character_id')->comment('상위(원) 캐릭터')->constrained('subculture_characters')->cascadeOnDelete();
            $table->foreignId('substitute_character_id')->comment('대체 캐릭터')->constrained('subculture_characters')->cascadeOnDelete();
            $table->string('note')->nullable()->comment('대체 조건 메모(예: 풀돌 기준, 스킬 10 필요)');
            $table->string('source', 40)->comment('출처(dc/arca/theqoo/ruliweb/manual)');
            $table->string('source_url', 500)->nullable();
            $table->unsignedTinyInteger('sort')->default(0)->comment('우선순위(낮을수록 우선)');
            $table->timestamps();

            $table->unique(['raid_id', 'character_id', 'substitute_character_id'], 'uniq_srs_raid_char_sub');
            $table->index(['raid_id', 'character_id'], 'idx_srs_raid_char');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_raid_substitutes');
    }
};
