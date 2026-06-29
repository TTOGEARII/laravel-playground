<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 로그인 사용자의 리딤코드 "교환 완료" 체크 기록.
 * 비로그인 사용자는 클라이언트 localStorage 에 저장하므로 이 테이블을 쓰지 않는다.
 * 동일성 키는 (사용자, 코드). 코드 행이 삭제되면 기록도 함께 정리(cascade)한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redeem_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('redeem_code_id')->constrained('redeem_codes')->cascadeOnDelete();
            $table->timestamp('redeemed_at')->nullable()->comment('교환 완료로 표시한 시각');
            $table->timestamps();

            $table->unique(['user_id', 'redeem_code_id'], 'uniq_rcr_user_code');
            $table->index('user_id', 'idx_rcr_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redeem_code_redemptions');
    }
};
