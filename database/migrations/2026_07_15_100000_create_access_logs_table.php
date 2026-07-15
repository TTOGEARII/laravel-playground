<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 외부 유저 접속 로그 — 방문 페이지·유입경로(referrer)·기기·UA·IP 기록.
 * 페이지 조회(웹 GET)만 남긴다. 응답 후(terminable) 기록해 요청 지연은 없다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->nullable()->comment('실제 클라이언트 IP(신뢰 프록시 뒤 X-Forwarded-For)');
            $table->string('device', 10)->default('pc')->comment('pc | mobile | tablet | bot');
            $table->string('method', 8)->default('GET');
            $table->string('path', 512)->comment('방문 경로(쿼리 포함)');
            $table->string('referrer', 512)->nullable()->comment('유입경로 — 직전 페이지 URL(외부 유입 판별)');
            $table->string('user_agent', 512)->nullable();
            $table->foreignId('user_id')->nullable()->comment('로그인 사용자면 id, 비로그인은 null');
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('device');
            $table->index('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
