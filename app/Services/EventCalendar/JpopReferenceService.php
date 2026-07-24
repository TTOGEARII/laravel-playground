<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\Sources\EventSidecarRunner;

/**
 * J-pop 내한 캘린더(j-pop-playlist.tistory.com/1109)를 "장르 판별 레퍼런스"로만 사용한다.
 * 달력에 표기하는 이벤트는 만들지 않는다 — 내한공연 사이트(festivallife) 공연 중 블로그 캘린더에
 * 같은 날·같은 아티스트로 올라와 있으면 genre 를 jpop 으로 확정(Gemini 오분류 'other' 도 바로잡는다).
 * 블로그에 없다고 J-pop 이 아닌 건 아니므로(등재 지연) 음성 신호로는 쓰지 않는다 — 나머지는 Gemini 폴백.
 */
class JpopReferenceService
{
    public function __construct(private EventSidecarRunner $runner) {}

    /**
     * @return array{matched: int, refs: int, skipped: bool}
     */
    public function tagFromReference(): array
    {
        $result = $this->runner->run(base_path('tools/raid-crawler/event-jpop-tistory.mjs'));
        if ($result === null) {
            return ['matched' => 0, 'refs' => 0, 'skipped' => true]; // 사이드카 실패 — 태깅만 건너뜀(수집 무영향)
        }

        // 블로그 항목 → (날짜, 아티스트 토큰) 레퍼런스
        $refs = [];
        foreach ((array) ($result['items'] ?? []) as $item) {
            $date = (string) ($item['date'] ?? '');
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $tokens = EventSyncService::artistTokens($title);
            if ($tokens !== []) {
                $refs[] = ['date' => $date, 'tokens' => $tokens];
            }
        }
        if ($refs === []) {
            return ['matched' => 0, 'refs' => 0, 'skipped' => false];
        }

        // 대조 대상: 아직 jpop 이 아닌 공연(레퍼런스 기간 이후 시작분 — 블로그는 미래 중심 6개월)
        $matched = 0;
        $concerts = Event::where('kind', 'concert')
            ->where(fn ($q) => $q->whereNull('genre')->orWhere('genre', '!=', 'jpop'))
            ->whereDate('starts_on', '>=', min(array_column($refs, 'date')))
            ->get();
        foreach ($concerts as $concert) {
            $start = $concert->starts_on->toDateString();
            $end = $concert->ends_on?->toDateString() ?? $start;
            $tokens = EventSyncService::artistTokens($concert->title);
            foreach ($refs as $ref) {
                if ($ref['date'] < $start || $ref['date'] > $end) {
                    continue; // 블로그는 공연일별 1항목 — 공연 기간 안의 날짜만 같은 공연으로 본다
                }
                if (array_intersect($tokens, $ref['tokens']) !== []) {
                    $concert->update(['genre' => 'jpop']);
                    $matched++;
                    break;
                }
            }
        }

        return ['matched' => $matched, 'refs' => count($refs), 'skipped' => false];
    }
}
