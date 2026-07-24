<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 행사 알림용 컬럼: 티켓오픈일(오늘 티켓오픈 푸시)·신규 다이제스트 발송 표시.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->date('ticket_opens_on')->nullable()->after('ticket_open_text')
                ->comment('티켓 오픈일(ticket_open_text 파싱 — 연도는 공연일 기준 보간)');
            $table->timestamp('notified_at')->nullable()->after('active_flg')
                ->comment('신규 행사 다이제스트 푸시 발송 시각(중복 발송 방지)');
            $table->index('ticket_opens_on');
        });

        // 기존 행은 다이제스트 발송 완료로 백필 — 배포 직후 첫 알림이 "새 행사 36건" 스팸이 되는 것 방지.
        \Illuminate\Support\Facades\DB::table('calendar_events')->whereNull('notified_at')->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['ticket_opens_on']);
            $table->dropColumn(['ticket_opens_on', 'notified_at']);
        });
    }
};
