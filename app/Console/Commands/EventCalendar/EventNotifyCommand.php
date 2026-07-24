<?php

namespace App\Console\Commands\EventCalendar;

use App\Services\EventCalendar\EventNotifyService;
use App\Services\Push\WebPushService;
use Illuminate\Console\Command;

/**
 * 행사 알림 푸시(매일 1회, 수집 직후): 오늘 티켓오픈 + 신규 행사 다이제스트.
 * 전역 구독자 대상이라 절제 — 대상 없으면 아무것도 보내지 않는다.
 */
class EventNotifyCommand extends Command
{
    protected $signature = 'event-calendar:notify';

    protected $description = '행사 알림 푸시(오늘 티켓오픈·신규 행사 다이제스트)';

    public function handle(EventNotifyService $notify, WebPushService $push): int
    {
        if (! $push->enabled()) {
            $this->line('웹푸시 비활성(VAPID 미설정) — 스킵');

            return self::SUCCESS;
        }

        // ① 오늘 티켓오픈(공연별 개별 푸시 — 상세로 링크)
        foreach ($notify->todayTicketOpens() as $event) {
            $body = trim(($event->ticket_open_text ?: '오늘').' · '.(string) $event->venue, ' ·');
            $result = $push->broadcast("🎫 오늘 티켓 오픈: {$event->title}", $body, "/event-calendar/{$event->id}");
            $this->info("티켓오픈 푸시: {$event->title} → 발송 {$result['sent']}");
        }

        // ② 신규 행사 다이제스트(있을 때만 1건)
        $new = $notify->unnotifiedNewEvents();
        if ($new->isNotEmpty()) {
            $result = $push->broadcast(
                "🗓️ 새 행사 {$new->count()}건 등록",
                $notify->digestBody($new),
                '/event-calendar',
            );
            $notify->markNotified($new);
            $this->info("다이제스트 푸시: {$new->count()}건 → 발송 {$result['sent']}");
        } else {
            $this->line('신규 행사 없음 — 다이제스트 스킵');
        }

        return self::SUCCESS;
    }
}
