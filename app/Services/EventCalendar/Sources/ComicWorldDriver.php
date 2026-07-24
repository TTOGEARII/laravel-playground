<?php

namespace App\Services\EventCalendar\Sources;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 코믹월드(comicw.net) 수집 드라이버.
 *
 * 홈의 행사 테이블을 채우는 숨은 JSON API 를 그대로 사용한다(정찰 실측·curl 재현 검증):
 *   POST /d/ajax.main.php  body: type=comic|mongu  (X-Requested-With 필요)
 * 응답 항목: title/place/startDate(ISO)/endDate/submitLink/ticketLink/guideLink...
 * 현재·예정 회차만 내려온다(과거 아카이브 없음). external_key = submitLink 의 회차번호(/e/335),
 * 없으면(문구전) 제목 폴백.
 */
class ComicWorldDriver implements EventSource
{
    public function code(): string
    {
        return 'comicworld';
    }

    public function collect(array $skipKeys = []): array
    {
        $endpoint = (string) config('event-calendar.sources.comicworld.endpoint');
        $types = (array) config('event-calendar.sources.comicworld.types', ['comic']);

        $events = [];
        foreach ($types as $type) {
            try {
                $res = Http::asForm()
                    ->withHeaders([
                        'X-Requested-With' => 'XMLHttpRequest',
                        'User-Agent' => (string) config('event-calendar.user_agent'),
                    ])
                    ->timeout(20)
                    ->post($endpoint, ['type' => $type]);

                if (! $res->ok()) {
                    Log::warning('코믹월드 API 응답 실패', ['type' => $type, 'status' => $res->status()]);

                    continue;
                }

                foreach ($this->itemsFrom($res->json()) as $item) {
                    $dto = $this->toDto($item, $type);
                    if ($dto !== null) {
                        $events[] = $dto;
                    }
                }
            } catch (\Throwable $e) {
                // 한 타입 실패가 다른 타입 수집을 막지 않게 방어
                Log::warning('코믹월드 수집 실패', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        return $events;
    }

    /**
     * 응답 형태 방어적 정규화 — 단일 객체/리스트/래퍼 키 어느 쪽이든 항목 배열로 만든다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemsFrom(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (isset($json['title'])) {           // 단일 객체
            return [$json];
        }
        if (array_is_list($json)) {            // 항목 리스트
            return array_values(array_filter($json, fn ($i) => is_array($i) && isset($i['title'])));
        }
        foreach ($json as $value) {            // {list: [...]} 류 래퍼
            if (is_array($value) && array_is_list($value)) {
                $items = array_values(array_filter($value, fn ($i) => is_array($i) && isset($i['title'])));
                if ($items !== []) {
                    return $items;
                }
            }
        }

        return [];
    }

    /** @param array<string, mixed> $item */
    private function toDto(array $item, string $type): ?CollectedEventData
    {
        $title = trim((string) ($item['title'] ?? ''));
        $startDate = trim((string) ($item['startDate'] ?? ''));
        if ($title === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return null;
        }
        $endDate = trim((string) ($item['endDate'] ?? ''));

        // external_key: 회차번호(/e/335) 우선, 없으면 제목(문구전은 submitLink 가 빌 수 있음)
        $submitLink = (string) ($item['submitLink'] ?? '');
        $externalKey = preg_match('#/e/(\d+)#', $submitLink, $m)
            ? $type.'-'.$m[1]
            : $type.'-'.md5($title.$startDate);

        $ticketLinks = [];
        if (! empty($item['ticketLink'])) {
            $ticketLinks[] = ['label' => '티켓 구매', 'url' => (string) $item['ticketLink']];
        }

        return new CollectedEventData(
            source: $this->code(),
            externalKey: $externalKey,
            kind: EventKind::Doujin,
            title: $title,
            startsOn: $startDate,
            endsOn: $endDate !== '' && $endDate !== $startDate ? $endDate : null,
            venue: trim((string) ($item['place'] ?? '')) ?: null,
            ticketLinks: $ticketLinks,
            extra: array_filter([
                'tag' => $item['tag'] ?? null,
                'status' => $item['status'] ?? null,
                'guide_link' => $item['guideLink'] ?? null,
                'stage_link' => $item['stageLink'] ?? null,
                'booth_map_link' => $item['boothMapLink'] ?? null,
                'type' => $type,
            ], fn ($v) => $v !== null && $v !== ''),
            detailUrl: ($item['guideLink'] ?? null) ?: ($submitLink ?: 'https://comicw.net/'),
        );
    }
}
