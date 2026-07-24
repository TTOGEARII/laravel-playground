<?php

namespace App\Services\EventCalendar\Sources;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * festivallife.kr /concert_k(내한공연) 수집 드라이버.
 *
 * 정찰 실측: 아임웹 SSR(브라우저 UA 필수·robots 허용), 목록 ?page=N(12건/페이지),
 * 상세 ?bmode=view&idx={idx}. 본문이 "일정:/장소:/가격:/오픈:/예매:" 레이블로 규칙적.
 * 장르 무관 전체 내한공연이 올라오므로 전부 수집하고 장르(jpop/other)는 Gemini 태깅이 담당.
 * 이미 저장된 idx($skipKeys)는 상세 요청을 건너뛴다(정중한 크롤 — 신규만 상세 방문).
 */
class FestivalLifeDriver implements EventSource
{
    public function code(): string
    {
        return 'festivallife';
    }

    public function collect(array $skipKeys = []): array
    {
        $cfg = (array) config('event-calendar.sources.festivallife');
        $base = rtrim((string) ($cfg['base_url'] ?? 'https://festivallife.kr'), '/');
        $board = (string) ($cfg['board'] ?? 'concert_k');
        $pages = max(1, (int) ($cfg['pages'] ?? 3));
        $delayMs = max(0, (int) ($cfg['delay_ms'] ?? 1200));
        $skip = array_flip($skipKeys);

        $events = [];
        for ($page = 1; $page <= $pages; $page++) {
            $html = $this->fetch("{$base}/{$board}".($page > 1 ? "?page={$page}" : ''));
            if ($html === null) {
                break; // 목록 실패 시 이후 페이지도 의미 없음 — 수집분만 반환
            }

            foreach ($this->listItems($html) as $idx => $listTitle) {
                if (isset($skip[$idx])) {
                    continue; // 이미 저장된 글 — 상세 방문 생략
                }
                usleep($delayMs * 1000);
                $detailUrl = "{$base}/{$board}/?bmode=view&idx={$idx}&t=board";
                $detailHtml = $this->fetch($detailUrl);
                if ($detailHtml === null) {
                    continue;
                }
                $dto = $this->parseDetail($idx, $listTitle, $detailUrl, $detailHtml);
                if ($dto !== null) {
                    $events[] = $dto;
                }
            }
        }

        return $events;
    }

    /** 목록 HTML 에서 게시글 idx → 제목 맵을 뽑는다(공지 글 제외). */
    private function listItems(string $html): array
    {
        $items = [];
        // 아이템 링크: href 에 bmode=view&idx=N. 제목은 링크 주변 .title 텍스트.
        if (! preg_match_all('/<a[^>]+href="[^"]*?bmode=view[^"]*?idx=(\d+)[^"]*"[^>]*>(.*?)<\/a>/su', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $match) {
            $idx = $match[1];
            // 실측: 모든 카드에 숨김(display:none) 공지 배지 <em> 이 들어있다 — 먼저 제거하고,
            // 그 후에도 notice-block 이 남아있는 글(진짜 공지)만 제외한다.
            $block = preg_replace('/<em[^>]*display:\s*none[^>]*>.*?<\/em>/su', '', $match[2]);
            if (str_contains($block, 'notice-block')) {
                continue;
            }
            $title = trim(html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5));
            if ($title !== '' && ! isset($items[$idx])) {
                $items[$idx] = preg_replace('/\s+/u', ' ', $title);
            }
        }

        return $items;
    }

    /** 상세 본문의 레이블(일정/장소/가격/오픈/예매)을 파싱해 DTO 로 만든다. */
    private function parseDetail(string $idx, string $listTitle, string $detailUrl, string $html): ?CollectedEventData
    {
        // og 메타(제목·포스터) — 목록 제목보다 정제돼 있으면 우선
        $title = $this->meta($html, 'og:title') ?? $listTitle;
        $title = trim(preg_replace('/\s*[|\-]\s*페스티벌라이프.*$/u', '', $title)) ?: $listTitle;
        // og:title 꼬리 "… : 내한공연 정보" 제거(사이트 SEO 접미)
        $title = trim(preg_replace('/\s*[:：]\s*내한공연\s*정보\s*$/u', '', $title)) ?: $title;
        $poster = $this->meta($html, 'og:image');

        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/iu', "\n", $html)), ENT_QUOTES | ENT_HTML5);

        // 신형("일정:")·구형("공연 일정:"/"티켓 가격"+다음 줄 나열) 레이블 모두 지원
        $schedule = $this->labelValue($text, ['(?:공연\s*)?일정', '(?:공연\s*)?일시', 'Dates?']);
        $venue = $this->labelValue($text, ['(?:공연\s*)?장소', 'Venue']);
        $price = $this->labelValue($text, ['(?:티켓\s*)?가격', 'Price', 'Tickets?']);
        $open = $this->labelValue($text, ['(?:티켓\s*)?오픈', 'Open']);
        $booking = $this->labelValue($text, ['(?:티켓\s*)?예매', 'Booking']);

        [$startsOn, $endsOn, $timeText] = $this->parseSchedule($schedule ?? '');
        if ($startsOn === null) {
            // 일정이 없거나 파싱 불가한 글(공지·후기류) — 캘린더에 올릴 수 없어 스킵
            Log::info('festivallife 일정 파싱 불가로 스킵', ['idx' => $idx, 'schedule' => $schedule, 'title' => $title]);

            return null;
        }

        return new CollectedEventData(
            source: $this->code(),
            externalKey: $idx,
            kind: EventKind::Concert,
            title: $title,
            startsOn: $startsOn,
            endsOn: $endsOn,
            timeText: $timeText,
            venue: $venue,
            priceText: $price,
            ticketOpenText: $open,
            extra: array_filter(['booking_text' => $booking]),
            posterUrl: $poster,
            detailUrl: $detailUrl,
        );
    }

    /**
     * "2026년 11월 18일 (수) 오후 8시" / "2026년 1월 17일 (토) ~ 18일 (일)" 파싱.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [시작일, 종료일, 시각 원문]
     */
    private function parseSchedule(string $schedule): array
    {
        // 일 범위 표기("4월 19~20일")도 지원 — 4그룹이 있으면 같은 달 종료일
        if (! preg_match('/(\d{4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})(?:\s*[~〜]\s*(\d{1,2}))?\s*일/u', $schedule, $m, PREG_OFFSET_CAPTURE)) {
            return [null, null, null];
        }
        $startsOn = sprintf('%04d-%02d-%02d', $m[1][0], $m[2][0], $m[3][0]);
        if (isset($m[4]) && $m[4][0] !== '') {
            $inlineEnd = sprintf('%04d-%02d-%02d', $m[1][0], $m[2][0], $m[4][0]);
            if ($inlineEnd > $startsOn) {
                $timeText = preg_match('/(오전|오후|낮|저녁)\s*\d{1,2}\s*시(?:\s*\d{1,2}\s*분)?/u', $schedule, $t)
                    ? preg_replace('/\s+/u', ' ', trim($t[0]))
                    : null;

                return [$startsOn, $inlineEnd, $timeText];
            }
        }
        $rest = substr($schedule, $m[0][1] + strlen($m[0][0]));

        // 종료일: "~ 2026년 1월 18일" 또는 "~ 18일" / "~ 2월 1일" (연·월 생략 시 시작일 기준 보간)
        $endsOn = null;
        if (preg_match('/[~〜]\s*(?:(\d{4})\s*년\s*)?(?:(\d{1,2})\s*월\s*)?(\d{1,2})\s*일/u', $rest, $e)) {
            $endsOn = sprintf('%04d-%02d-%02d',
                $e[1] !== '' ? (int) $e[1] : (int) $m[1][0],
                $e[2] !== '' ? (int) $e[2] : (int) $m[2][0],
                (int) $e[3],
            );
            if ($endsOn <= $startsOn) {
                $endsOn = null; // 역전(파싱 오염)은 버림
            }
        }

        $timeText = preg_match('/(오전|오후|낮|저녁)\s*\d{1,2}\s*시(?:\s*\d{1,2}\s*분)?/u', $schedule, $t)
            ? preg_replace('/\s+/u', ' ', trim($t[0]))
            : null;

        return [$startsOn, $endsOn, $timeText];
    }

    /**
     * "레이블: 값" 형식에서 값을 뽑는다(한/영 레이블). 구형 포스트는 레이블 줄에 값이 없고
     * 다음 줄들("- 스탠딩 …")에 나열되므로, 같은 줄이 비면 이어지는 나열 줄(최대 4줄)을 합친다.
     */
    private function labelValue(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (! preg_match('/^\s*(?:'.$label.')\s*[:：]?\s*(.*)$/miu', $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $value = trim(preg_replace('/\s+/u', ' ', $m[1][0]));
            if ($value === '') {
                // 다음 줄들에서 "-"/"·" 나열 수집(구형 "티켓 가격" ↵ "- 스탠딩 …" 형식)
                $rest = substr($text, $m[0][1] + strlen($m[0][0]));
                $collected = [];
                foreach (array_slice(preg_split('/\n/', $rest), 0, 6) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (! preg_match('/^[-·•]/u', $line)) {
                        break; // 나열이 끝나면 중단
                    }
                    $collected[] = trim(preg_replace('/\s+/u', ' ', ltrim($line, '-·• ')));
                    if (count($collected) >= 4) {
                        break;
                    }
                }
                $value = implode(', ', $collected);
            }
            if ($value !== '') {
                return mb_substr($value, 0, 500);
            }
        }

        return null;
    }

    private function meta(string $html, string $property): ?string
    {
        if (preg_match('/<meta[^>]+property="'.preg_quote($property, '/').'"[^>]+content="([^"]*)"/iu', $html, $m)
            || preg_match('/<meta[^>]+content="([^"]*)"[^>]+property="'.preg_quote($property, '/').'"/iu', $html, $m)) {
            $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function fetch(string $url): ?string
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('event-calendar.user_agent')])
                ->timeout(20)
                ->get($url);

            return $res->ok() ? $res->body() : null;
        } catch (\Throwable $e) {
            Log::warning('festivallife 요청 실패', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
