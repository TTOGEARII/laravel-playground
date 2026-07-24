<?php

namespace App\Services\EventCalendar\Sources;

use App\Services\EventCalendar\Sources\Concerns\FetchesHtml;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Carbon;

/**
 * 킨텍스(kintex.com) 행사 캘린더 드라이버 — 게임사 오프라인 행사(블아 4주년 페스티벌 실증)·
 * 대형 서브컬쳐 행사가 잡힌다. JSP SSR·robots 전면 허용(정찰 실측).
 * 목록: /web/ko/event/list.do?searchStartDt&searchEndDt (grid-frame-cell 단위,
 *   fnView('./view.do', {seq}) 의 seq = external_key, item-subject/client/date 클래스)
 * 상세: /web/ko/event/view.do?seq= — "주 최/입 장 료/관 람 시 간" 라벨(공백 낀 표기).
 * 산업 전시가 대부분이라 VenueEventFilter(키워드·주최 화이트리스트)로 서브컬쳐만 통과.
 */
class KintexDriver implements EventSource
{
    use FetchesHtml;

    public function __construct(private VenueEventFilter $filter) {}

    public function code(): string
    {
        return 'kintex';
    }

    public function collect(array $skipKeys = []): array
    {
        $base = rtrim((string) config('event-calendar.sources.kintex.base'), '/');
        $window = (int) config('event-calendar.venues.window_days', 120);
        $delayMs = (int) config('event-calendar.venues.delay_ms', 1000);
        $start = Carbon::today()->toDateString();
        $end = Carbon::today()->addDays($window)->toDateString();
        $skip = array_flip($skipKeys);

        // 1) 목록에서 후보 수집(서브컬쳐 키워드 매칭 or 문화행사 카테고리 → 상세에서 주최 확인)
        $candidates = [];
        for ($page = 1; $page <= 5; $page++) {
            $html = $this->fetchHtml("{$base}/web/ko/event/list.do?pageIndex={$page}&pageUnit=30&searchStartDt={$start}&searchEndDt={$end}");
            if ($html === null) {
                break;
            }
            $cells = $this->parseCells($html);
            if ($cells === []) {
                break; // 마지막 페이지 이후
            }
            foreach ($cells as $cell) {
                $candidates[$cell['seq']] ??= $cell;
            }
        }

        // 2) 필터 + 상세 보강
        $events = [];
        foreach ($candidates as $cell) {
            $key = 'kintex-'.$cell['seq'];
            if (isset($skip[$key]) || $this->filter->isDedupe($cell['title'])) {
                continue;
            }
            $keywordHit = $this->filter->isSubculture($cell['title']);
            $isCulture = str_contains($cell['category'], '문화');
            if (! $keywordHit && ! $isCulture) {
                continue; // 산업 전시 — 상세 방문도 하지 않음
            }

            usleep($delayMs * 1000);
            $detailUrl = "{$base}/web/ko/event/view.do?seq={$cell['seq']}";
            $detail = $this->parseDetail($this->fetchHtml($detailUrl) ?? '');
            if (! $keywordHit && ! $this->filter->isSubculture($cell['title'], $detail['host'] ?? null)) {
                continue; // 문화행사지만 주최도 서브컬쳐가 아님(워터밤 등)
            }

            [$startsOn, $endsOn] = $this->parseDateRange($cell['dates']);
            if ($startsOn === null) {
                continue;
            }
            $events[] = new CollectedEventData(
                source: $this->code(),
                externalKey: $key,
                kind: $this->filter->kindFor($cell['title']),
                title: $cell['title'],
                startsOn: $startsOn,
                endsOn: $endsOn,
                timeText: $detail['hours'] ?? null,
                venue: trim('킨텍스 '.$cell['halls']),
                priceText: $detail['fee'] ?? null,
                extra: array_filter([
                    'host' => $detail['host'] ?? null,
                    'homepage' => $detail['homepage'] ?? null,
                    'category' => $cell['category'],
                ]),
                detailUrl: $detailUrl,
            );
        }

        return $events;
    }

    /** @return array<int, array{seq: string, title: string, halls: string, dates: string, category: string}> */
    private function parseCells(string $html): array
    {
        $cells = [];
        foreach (array_slice(explode('grid-frame-cell', $html), 1) as $chunk) {
            if (! preg_match('/fnView\(\'\.\/view\.do\',\s*(\d+)\)/', $chunk, $seq)) {
                continue;
            }
            $title = preg_match('/class="item-subject"[^>]*>(.*?)<\/[a-z]+>/su', $chunk, $m) ? $this->textOf($m[1]) : '';
            $halls = preg_match('/class="item-client"[^>]*>(.*?)<\/[a-z]+>/su', $chunk, $m) ? $this->textOf($m[1]) : '';
            $dates = preg_match('/class="item-date"[^>]*>(.*?)<\/[a-z]+>/su', $chunk, $m) ? $this->textOf($m[1]) : '';
            $category = preg_match('/class="ko-txt"[^>]*>(.*?)<\/div>/su', $chunk, $m) ? $this->textOf($m[1]) : '';
            if ($title !== '') {
                $cells[] = ['seq' => $seq[1], 'title' => $title, 'halls' => $halls, 'dates' => $dates, 'category' => $category];
            }
        }

        return $cells;
    }

    /** 상세의 "주 최/입 장 료/관 람 시 간/홈페이지" 라벨 값 추출(공백 낀 라벨·약관 텍스트 오염 대응). */
    private function parseDetail(string $html): array
    {
        // 태그를 구분자로 치환한 텍스트에서 "라벨|값" 순서 파싱
        $text = preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<[^>]+>/u', '|', $html)));

        $grab = function (string $labelPattern) use ($text): ?string {
            if (preg_match('/'.$labelPattern.'\s*\|+\s*([^|]{1,200})/u', $text, $m)) {
                $value = trim($m[1]);

                return $value !== '' ? $value : null;
            }

            return null;
        };

        return [
            'host' => $grab('주\s*최'),
            'fee' => $grab('입\s*장\s*료'),
            'hours' => $grab('관\s*람\s*시\s*간'),
            'homepage' => $grab('홈페이지'),
        ];
    }
}
