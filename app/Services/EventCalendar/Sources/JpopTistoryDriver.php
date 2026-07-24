<?php

namespace App\Services\EventCalendar\Sources;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;

/**
 * J-pop 내한 캘린더(j-pop-playlist.tistory.com/1109) 드라이버 — 큐레이션된 J-pop 전용
 * 캘린더라 장르를 jpop 으로 확정 수집한다(Gemini 태깅 불필요).
 * 사이드카(event-jpop-tistory.mjs)가 월별 pill 의 data 속성(date/title/location/link)을 내려주면
 * 같은 제목의 연속 날짜를 기간으로 묶는다(비연속이면 별도 회차로 유지).
 * festivallife 와 같은 공연이 겹칠 수 있으나 EventSyncService 의 교차 소스 공연 중복 방지가 거른다.
 */
class JpopTistoryDriver implements EventSource
{
    public function __construct(private EventSidecarRunner $runner) {}

    public function code(): string
    {
        return 'jpoptistory';
    }

    public function collect(array $skipKeys = []): array
    {
        $result = $this->runner->run(base_path('tools/raid-crawler/event-jpop-tistory.mjs'));
        if ($result === null) {
            return [];
        }

        // 제목별 날짜 묶기
        $byTitle = [];
        foreach ($result['items'] as $item) {
            $date = (string) ($item['date'] ?? '');
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $byTitle[$title]['dates'][] = $date;
            $byTitle[$title]['location'] = $item['location'] ?? null;
            $byTitle[$title]['link'] = $item['link'] ?? null;
            $byTitle[$title]['category'] = $item['category'] ?? 'concert';
        }

        $events = [];
        foreach ($byTitle as $title => $info) {
            $dates = array_values(array_unique($info['dates']));
            sort($dates);
            // 연속 날짜는 한 공연의 기간, 비연속은 별도 회차(예: 5월·9월 두 번 공연)
            foreach ($this->consecutiveRuns($dates) as $run) {
                $startsOn = $run[0];
                $endsOn = count($run) > 1 ? end($run) : null;
                $events[] = new CollectedEventData(
                    source: $this->code(),
                    externalKey: 'jpt-'.md5($title.'|'.$startsOn),
                    kind: EventKind::Concert,
                    title: $title,
                    startsOn: $startsOn,
                    endsOn: $endsOn,
                    venue: $info['location'] ?: null,
                    ticketLinks: $info['link'] ? [['label' => '예매하기', 'url' => (string) $info['link']]] : [],
                    extra: array_filter(['category' => $info['category']]),
                    detailUrl: 'https://j-pop-playlist.tistory.com/1109',
                    genre: 'jpop', // 큐레이션 소스 — 장르 확정
                );
            }
        }

        return $events;
    }

    /**
     * 정렬된 날짜 배열을 연속 구간들로 나눈다(간격 1일 이하 = 같은 공연).
     *
     * @param  array<int, string>  $dates
     * @return array<int, array<int, string>>
     */
    private function consecutiveRuns(array $dates): array
    {
        $runs = [];
        $current = [];
        foreach ($dates as $date) {
            if ($current !== [] && (strtotime($date) - strtotime(end($current))) > 86400) {
                $runs[] = $current;
                $current = [];
            }
            $current[] = $date;
        }
        if ($current !== []) {
            $runs[] = $current;
        }

        return $runs;
    }
}
