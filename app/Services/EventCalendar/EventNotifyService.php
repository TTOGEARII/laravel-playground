<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 행사 알림 페이로드 구성 — 전역 웹푸시(리딤코드와 공유) 대상이라 스팸이 되지 않게 절제:
 * ① 오늘 티켓오픈(공연별 1건, 실제로 드묾) ② 신규 행사 다이제스트(있을 때만 하루 1건 요약).
 */
class EventNotifyService
{
    /** 오늘이 티켓오픈일인 공연들(아직 오픈 알림은 중복 걱정 없음 — 하루 1회 실행 전제). */
    public function todayTicketOpens(): Collection
    {
        return Event::where('active_flg', true)
            ->whereDate('ticket_opens_on', Carbon::today())
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * 신규 행사 다이제스트 대상(아직 다이제스트에 안 실린 것). 반환 후 markNotified 로 표시.
     */
    public function unnotifiedNewEvents(): Collection
    {
        return Event::where('active_flg', true)
            ->whereNull('notified_at')
            ->where('starts_on', '>=', Carbon::today()->toDateString()) // 지난 행사(백필)는 알리지 않음
            ->orderBy('starts_on')
            ->get();
    }

    public function markNotified(Collection $events): void
    {
        if ($events->isNotEmpty()) {
            Event::whereIn('id', $events->pluck('id'))->update(['notified_at' => now()]);
        }
    }

    /** 다이제스트 문구: "YOASOBI 내한공연 외 2건" */
    public function digestBody(Collection $events): string
    {
        $first = $events->first()->title;
        $rest = $events->count() - 1;

        return $rest > 0 ? "{$first} 외 {$rest}건" : $first;
    }
}
