<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 수동 IP 차단 목록. BlockExternalBots 미들웨어가 이 목록의 IP 를 403 으로 막는다.
 * 접속 로그(access_logs) 분석으로 공격 IP 를 찾아 ip:block 커맨드로 등록한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique()->comment('차단 대상 IP(IPv4/IPv6)');
            $table->string('reason', 255)->nullable()->comment('차단 사유(공격 유형·메모)');
            $table->timestamps();
        });

        // 코멘트는 MySQL 전용 — SQLite(테스트) 에선 스킵.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `blocked_ips` COMMENT = '수동 IP 차단 목록(BlockExternalBots 가 403)'");
            DB::statement("ALTER TABLE `blocked_ips` MODIFY `created_at` timestamp NULL COMMENT '차단 등록 일시'");
            DB::statement("ALTER TABLE `blocked_ips` MODIFY `updated_at` timestamp NULL COMMENT '수정 일시'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
