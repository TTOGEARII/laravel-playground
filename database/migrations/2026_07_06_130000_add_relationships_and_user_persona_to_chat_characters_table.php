<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 캐릭터 페르소나 확장 — 작품 속 인물 관계와 대화 상대(유저) 페르소나.
 * 둘 다 시스템 프롬프트에 반영돼 대화 맥락(세계관 인물 언급·유저 역할)을 풍부하게 만든다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->text('relationships')->nullable()->after('world_setting')
                ->comment('작품 세계관 속 주요 인물들과의 관계 (인물명·관계·호칭 등 자유 서술)');
            $table->text('user_persona')->nullable()->after('relationships')
                ->comment('대화 상대(유저)의 기본 페르소나 (역할·설정 자유 서술)');
        });
    }

    public function down(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->dropColumn(['relationships', 'user_persona']);
        });
    }
};
