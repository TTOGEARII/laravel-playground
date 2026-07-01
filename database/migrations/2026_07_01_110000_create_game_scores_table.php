<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 내가 만든 미니게임(외부 게임 제외)의 점수 랭킹 기록.
        Schema::create('game_scores', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 40);                                 // 게임 식별자 (예: tetris, vampire-survival)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // 로그인 사용자면 연결(비로그인 null)
            $table->string('nickname', 20);                                 // 표시 닉네임 (로그인: 회원명 / 게스트: 입력값)
            $table->unsignedBigInteger('score')->default(0);
            $table->timestamps();

            // 게임별 상위 점수 조회 최적화.
            $table->index(['game_key', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_scores');
    }
};
