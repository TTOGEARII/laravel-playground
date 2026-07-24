<?php

namespace App\Services\EventCalendar\Sources;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Facades\Log;

/**
 * 일러스타페스(illustar.net) 수집 드라이버 — Playwright 사이드카(event-illustar.mjs)로
 * 홈 배너의 회차 텍스트를 받아 여기서 날짜·장소를 파싱한다(파싱은 PHP 몫이라 테스트 용이).
 * 실측 형식: "2026년 8월 1, 2일 (토, 일)\n부산 벡스코 제2전시장 4홀"
 * 회차 번호는 로고 이미지 안에만 있어 DOM 에 없음 → 제목은 "일러스타 페스 (도시)",
 * external_key 는 시작일 기반(연 5~6회라 날짜로 유일).
 */
class IllustarDriver implements EventSource
{
    public function __construct(private EventSidecarRunner $runner) {}

    public function code(): string
    {
        return 'illustar';
    }

    public function collect(array $skipKeys = []): array
    {
        $script = base_path('tools/raid-crawler/event-illustar.mjs');
        $result = $this->runner->run($script);
        if ($result === null) {
            return []; // 사이드카 실패 — 로그는 러너가 남김, 다른 소스에 영향 없음
        }

        $events = [];
        foreach ($result['items'] as $item) {
            $dto = $this->parse((string) ($item['text'] ?? ''));
            if ($dto !== null) {
                $events[] = $dto;
            }
        }

        return $events;
    }

    private function parse(string $text): ?CollectedEventData
    {
        // 날짜: "2026년 8월 1, 2일" — 일자 나열(1, 2)에서 최소=시작·최대=종료
        if (! preg_match('/(\d{4})\s*년\s*(\d{1,2})\s*월\s*([\d,\s]+)\s*일/u', $text, $m)) {
            return null;
        }
        $days = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', trim($m[3])))));
        if ($days === []) {
            return null;
        }
        $startsOn = sprintf('%04d-%02d-%02d', $m[1], $m[2], min($days));
        $endsOn = count($days) > 1 ? sprintf('%04d-%02d-%02d', $m[1], $m[2], max($days)) : null;

        // 장소: 날짜가 아닌 줄 중 가장 그럴듯한 것(한글 포함·요일 괄호 제외)
        $venue = null;
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\d{4}\s*년/u', $line) || preg_match('/^\([일월화수목금토,\s]+\)$/u', $line)) {
                continue;
            }
            if (preg_match('/[가-힣]/u', $line)) {
                $venue = mb_substr($line, 0, 190);
                break;
            }
        }
        if ($venue === null) {
            Log::info('일러스타 장소 파싱 불가 — 날짜만 저장', ['text' => mb_substr($text, 0, 100)]);
        }

        // 제목: "일러스타 페스 (도시)" — 장소 첫 어절을 도시로 사용
        $city = $venue !== null ? explode(' ', $venue)[0] : null;
        $title = '일러스타 페스'.($city ? " ({$city})" : '');

        return new CollectedEventData(
            source: $this->code(),
            externalKey: 'illustar-'.$startsOn,
            kind: EventKind::Doujin,
            title: $title,
            startsOn: $startsOn,
            endsOn: $endsOn,
            venue: $venue,
            detailUrl: 'https://illustar.net/',
        );
    }
}
