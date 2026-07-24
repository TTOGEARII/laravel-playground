<?php

namespace App\Services\EventCalendar\Sources;

use App\Services\EventCalendar\Sources\Concerns\FetchesHtml;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Carbon;

/**
 * SETEC(setec.or.kr) 전시 일정 드라이버 — 디.페스타 등 중소 동인 행사가 잡힌다(정찰 실증).
 * 목록: /front/schedule/list.do?searchSDate&searchEDate — fn_view('{sIdx}') 앵커 +
 *   <strong>제목</strong> + "기간 : Y-m-d ~ Y-m-d" + "장소 : 제N전시실" + viewImg 포스터.
 * 카테고리 필드가 없어 키워드/주최 필터만으로 판별(행사 수가 적어 전수 필터 가능).
 */
class SetecDriver implements EventSource
{
    use FetchesHtml;

    public function __construct(private VenueEventFilter $filter) {}

    public function code(): string
    {
        return 'setec';
    }

    public function collect(array $skipKeys = []): array
    {
        $base = rtrim((string) config('event-calendar.sources.setec.base'), '/');
        $window = (int) config('event-calendar.venues.window_days', 120);
        $start = Carbon::today()->toDateString();
        $end = Carbon::today()->addDays($window)->toDateString();
        $skip = array_flip($skipKeys);

        $events = [];
        for ($page = 1; $page <= 5; $page++) {
            $html = $this->fetchHtml("{$base}/front/schedule/list.do?pageIndex={$page}&searchSDate={$start}&searchEDate={$end}");
            if ($html === null) {
                break;
            }
            $items = $this->parseItems($html);
            if ($items === []) {
                break;
            }
            foreach ($items as $item) {
                $key = 'setec-'.$item['sIdx'];
                if (isset($skip[$key]) || isset($events[$key])) {
                    continue;
                }
                if ($this->filter->isDedupe($item['title']) || ! $this->filter->isSubculture($item['title'])) {
                    continue;
                }
                $events[$key] = new CollectedEventData(
                    source: $this->code(),
                    externalKey: $key,
                    kind: $this->filter->kindFor($item['title']),
                    title: $item['title'],
                    startsOn: $item['start'],
                    endsOn: $item['end'],
                    venue: trim('SETEC '.$item['place']),
                    posterUrl: $item['poster'] !== null ? $base.$item['poster'] : null,
                    detailUrl: "{$base}/front/schedule/calendar.do", // 상세는 POST 폼이라 목록/캘린더로 링크
                );
            }
        }

        return array_values($events);
    }

    /** @return array<int, array{sIdx: string, title: string, start: ?string, end: ?string, place: string, poster: ?string}> */
    private function parseItems(string $html): array
    {
        $items = [];
        foreach (array_slice(preg_split('/onclick="fn_view\(/', $html), 1) as $chunk) {
            if (! preg_match('/^\'(\d+)\'\)/', $chunk, $id)) {
                continue;
            }
            $title = preg_match('/<strong>(.*?)<\/strong>/su', $chunk, $m) ? $this->textOf($m[1]) : '';
            if ($title === '') {
                continue;
            }
            $range = preg_match('/기간\s*:\s*([\d\-.~\s]+)/u', $chunk, $m) ? $m[1] : '';
            [$start, $end] = $this->parseDateRange($range);
            if ($start === null) {
                continue;
            }
            $place = preg_match('/장소\s*:\s*([^<]+)/u', $chunk, $m) ? trim($m[1]) : '';
            $poster = preg_match('/src="(\/file\/viewImg\.do\?fIdx=\d+)"/', $chunk, $m) ? $m[1] : null;
            $items[] = ['sIdx' => $id[1], 'title' => $title, 'start' => $start, 'end' => $end, 'place' => $place, 'poster' => $poster];
        }

        return $items;
    }
}
