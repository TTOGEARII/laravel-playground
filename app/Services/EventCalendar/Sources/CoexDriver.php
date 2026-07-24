<?php

namespace App\Services\EventCalendar\Sources;

use App\Services\EventCalendar\Sources\Concerns\FetchesHtml;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Carbon;

/**
 * 코엑스(coex.co.kr) 행사 일정 드라이버 — 서울팝콘·서울일러스트레이션페어 등(정찰 실증).
 * 목록: /event/full-schedules/?search_start_date=Y.m.d&search_end_date=Y.m.d (날짜 구분자 점!)
 *   .BlogEventItem 카드: 카테고리 | 제목 | Y.m.d - Y.m.d | Hall — 키워드 파라미터가 무동작이라
 *   날짜 범위 전수 수집 후 키워드 필터. 상세 slug = external_key.
 * 포스터: /wp-content/uploads 가 robots Disallow — 포스터 수집은 하지 않는다.
 */
class CoexDriver implements EventSource
{
    use FetchesHtml;

    public function __construct(private VenueEventFilter $filter) {}

    public function code(): string
    {
        return 'coex';
    }

    public function collect(array $skipKeys = []): array
    {
        $base = rtrim((string) config('event-calendar.sources.coex.base'), '/');
        $window = (int) config('event-calendar.venues.window_days', 120);
        $start = Carbon::today()->format('Y.m.d');
        $end = Carbon::today()->addDays($window)->format('Y.m.d');
        $skip = array_flip($skipKeys);

        $events = [];
        for ($page = 1; $page <= 12; $page++) { // 12건/페이지 고정 — 120일 윈도우면 수 페이지
            $html = $this->fetchHtml("{$base}/event/full-schedules/?var_page={$page}&search_start_date={$start}&search_end_date={$end}&list_type=LIST");
            if ($html === null) {
                break;
            }
            $cards = $this->parseCards($html);
            if ($cards === []) {
                break;
            }
            foreach ($cards as $card) {
                $key = 'coex-'.$card['slug'];
                if (isset($skip[$key]) || isset($events[$key])) {
                    continue;
                }
                if ($this->filter->isDedupe($card['title']) || ! $this->filter->isSubculture($card['title'])) {
                    continue;
                }
                $events[$key] = new CollectedEventData(
                    source: $this->code(),
                    externalKey: $key,
                    kind: $this->filter->kindFor($card['title']),
                    title: $card['title'],
                    startsOn: $card['start'],
                    endsOn: $card['end'],
                    venue: trim('코엑스 '.$card['hall']),
                    extra: array_filter(['category' => $card['category']]),
                    detailUrl: $card['url'],
                );
            }
        }

        return array_values($events);
    }

    /** @return array<int, array{slug: string, url: string, title: string, start: ?string, end: ?string, hall: string, category: string}> */
    private function parseCards(string $html): array
    {
        $cards = [];
        foreach (array_slice(preg_split('/class=[\'"]BlogEventItem[\'"]/', $html), 1) as $chunk) {
            // 상세 링크(슬러그) — 쿼리 스트링 제거
            if (! preg_match('/href=[\'"]https?:\/\/www\.coex\.co\.kr\/exhibitions\/([^\'"?\/]+)\/?[^\'"]*[\'"]/u', $chunk, $m)) {
                continue;
            }
            $slug = urldecode($m[1]);
            $url = 'https://www.coex.co.kr/exhibitions/'.$m[1].'/';

            // 카드 텍스트: |카테고리| |제목| |Y.m.d - Y.m.d| |Hall|
            $text = preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<[^>]+>/u', '|', mb_substr($chunk, 0, 4000))));
            if (! preg_match('/\|\s*(Exhibition|Convention|Pop-?up\/?Event|Event|Performance)\s*\|\s*\|?\s*([^|]{2,150})\|/iu', $text, $t)) {
                continue;
            }
            $category = trim($t[1]);
            $title = trim($t[2]);
            [$start, $end] = $this->parseDateRange($text);
            if ($start === null || $title === '') {
                continue;
            }
            $hall = preg_match('/\|\s*((?:Hall|홀)[^|]{0,40})\|/iu', $text, $h) ? trim($h[1]) : '';
            $cards[] = ['slug' => $slug, 'url' => $url, 'title' => $title, 'start' => $start, 'end' => $end, 'hall' => $hall, 'category' => $category];
        }

        return $cards;
    }
}
