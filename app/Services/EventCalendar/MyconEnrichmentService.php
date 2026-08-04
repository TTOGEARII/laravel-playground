<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * mycon.me 상세로 블로그(jpoptistory) 공연의 예매 정보를 보강한다.
 * 블로그 pill 링크가 mycon 상세(extra.mycon_url)면 그 페이지의 JSON-LD(schema.org Event)에서
 * 티켓오픈 일시(offers.validFrom, UTC → KST 변환)·최저가·예매처 링크·포스터를 뽑아 행을 채운다.
 * 정찰 실측: Next.js SSR 이라 HTTP fetch 만으로 충분(브라우저 불필요), robots 허용(/api/ 만 금지라 미사용),
 * 오픈일 미정은 연도 9999 sentinel. 대상은 미래 공연 중 오픈 정보가 없거나 아직 오픈 전인 행만(정중한 크롤).
 */
class MyconEnrichmentService
{
    /** 예매처 도메인 → 표시 라벨 */
    private const PLATFORMS = [
        'yes24' => 'YES24',
        'interpark' => '인터파크(NOL)',
        'melon' => '멜론티켓',
        'ticketlink' => '티켓링크',
    ];

    /**
     * @return array{enriched: int, checked: int, failed: int}
     */
    public function enrich(): array
    {
        $delayMs = max(0, (int) config('event-calendar.mycon.delay_ms', 1500));
        $stats = ['enriched' => 0, 'checked' => 0, 'failed' => 0];

        $targets = Event::where('source', 'jpoptistory')
            ->where('active_flg', true)
            ->whereDate('starts_on', '>=', Carbon::today())
            ->where(fn ($q) => $q->whereNull('ticket_opens_on')->orWhereDate('ticket_opens_on', '>=', Carbon::today()))
            ->get()
            ->filter(fn (Event $e) => filled($e->extra['mycon_url'] ?? null));

        foreach ($targets as $event) {
            $stats['checked']++;
            $parsed = $this->fetchDetail((string) $event->extra['mycon_url']);
            if ($parsed === null) {
                $stats['failed']++;

                continue;
            }
            $updates = $this->buildUpdates($event, $parsed);
            if ($updates !== []) {
                $event->update($updates);
                $stats['enriched']++;
            }
            usleep($delayMs * 1000);
        }

        return $stats;
    }

    /**
     * mycon 상세를 파싱해 정규화된 배열로 돌려준다(실패 시 null).
     *
     * @return array{ticket_opens_at: ?Carbon, time_known: bool, price_min: ?string, ticket_url: ?string, venue: ?string, poster: ?string}|null
     */
    public function fetchDetail(string $url): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('event-calendar.user_agent')])
                ->timeout(20)
                ->get($url);
            if (! $res->ok()) {
                return null;
            }
            $html = $res->body();
        } catch (\Throwable $e) {
            Log::warning('mycon 상세 요청 실패', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $ld = $this->jsonLdEvent($html);
        if ($ld === null) {
            Log::info('mycon JSON-LD 없음(마크업 변경?)', ['url' => $url]);

            return null;
        }

        $offer = collect((array) ($ld['offers'] ?? []))->first(fn ($o) => is_array($o)) ?? (is_array($ld['offers'] ?? null) ? $ld['offers'] : null);
        // 오픈일: UTC ISO → KST. 연도 9999 는 "미정" sentinel.
        $opensAt = null;
        $validFrom = is_array($offer) ? ($offer['validFrom'] ?? null) : null;
        if (is_string($validFrom) && $validFrom !== '' && ! str_starts_with($validFrom, '9999')) {
            try {
                $opensAt = Carbon::parse($validFrom)->setTimezone('Asia/Seoul');
            } catch (\Throwable) {
                $opensAt = null;
            }
        }

        // 데이터 품질 보정 — mycon 은 실제 예매일시를 모르면 (a) 등록/공지한 날짜를 그대로 open_date 로
        // 찍거나 (b) 시각을 정오(KST 12:00 = UTC 03:00)로 채운다. 두 경우를 걸러 잘못된 예매일 방지.
        $timeKnown = true;
        if ($opensAt !== null) {
            if (in_array($opensAt->toDateString(), $this->announcementDates($html), true)) {
                $opensAt = null; // 공지/등록일 == open_date → mycon 이 실제 예매일을 모른다 → 신뢰 불가
            } elseif ($opensAt->format('H:i:s') === '12:00:00') {
                $timeKnown = false; // 시각 미상 기본값(정오) — 날짜만 신뢰하고 시각은 표기하지 않음
            }
        }

        $price = is_array($offer) && is_numeric($offer['price'] ?? null)
            ? number_format((float) $offer['price']).'원~'
            : null;

        return [
            'ticket_opens_at' => $opensAt,
            'time_known' => $timeKnown,
            'price_min' => $price,
            'ticket_url' => is_array($offer) ? ($offer['url'] ?? null) : null,
            'venue' => data_get($ld, 'location.name'),
            'poster' => $this->ogImage($html),
        ];
    }

    /**
     * mycon 이 이 공연을 등록·트윗한 날짜(RSC created_at/tweeted_at) 집합. open_date 가 여기 들어가면
     * "예매일을 몰라 공지일을 찍은 것"으로 본다. 마크업 변경으로 못 찾으면 빈 배열(거르지 않음).
     *
     * @return array<int, string> Y-m-d 목록
     */
    private function announcementDates(string $html): array
    {
        // RSC 는 JS 문자열이라 따옴표가 \" 로 이스케이프됨 — 키와 날짜 사이 구분자를 느슨하게 매칭
        if (! preg_match_all('/(?:created_at|tweeted_at)[\\\\":\s]+(\d{4}-\d{2}-\d{2})/', $html, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /** 파싱 결과를 이벤트 갱신 배열로(빈 값으로 기존 값을 지우지 않는다). */
    private function buildUpdates(Event $event, array $parsed): array
    {
        $updates = [];
        $extra = (array) $event->extra;
        // 예매일 소유권: 미표기/mycon 은 mycon 이 관리, x/news 는 크로스체크가 채운 값이라 건드리지 않음
        $mysconOwns = in_array($extra['ticket_open_source'] ?? 'mycon', ['mycon'], true);

        if ($parsed['ticket_opens_at'] !== null && $mysconOwns) {
            $opens = $parsed['ticket_opens_at'];
            $updates['ticket_opens_on'] = $opens->toDateString();
            $updates['ticket_open_text'] = $this->formatOpenText($opens, $parsed['time_known']);
            $extra['ticket_open_source'] = 'mycon';
        } elseif ($parsed['ticket_opens_at'] === null && $mysconOwns && $event->ticket_opens_on !== null) {
            // mycon 이 신뢰 불가로 판단(공지일=예매일 등) → 기존 mycon 값 제거(크로스체크가 채우도록)
            $updates['ticket_opens_on'] = null;
            $updates['ticket_open_text'] = null;
            unset($extra['ticket_open_source']);
        }
        if ($extra !== (array) $event->extra) {
            $updates['extra'] = $extra;
        }

        if ($parsed['price_min'] !== null && $event->price_text === null) {
            $updates['price_text'] = $parsed['price_min'];
        }
        if ($parsed['venue'] !== null && blank($event->venue)) {
            $updates['venue'] = $parsed['venue'];
        }
        if ($parsed['poster'] !== null && blank($event->poster_url)) {
            $updates['poster_url'] = $parsed['poster'];
        }
        if (is_string($parsed['ticket_url'] ?? null) && $parsed['ticket_url'] !== '') {
            $links = [['label' => '예매하기 ('.$this->platformLabel($parsed['ticket_url']).')', 'url' => $parsed['ticket_url']]];
            if ($event->ticket_links !== $links) {
                $updates['ticket_links'] = $links;
            }
        }

        return $updates;
    }

    /** "8월 8일 (토) 오후 8시" — KST 문구. 시각 미상($timeKnown=false)이면 날짜만 표기. */
    private function formatOpenText(Carbon $opens, bool $timeKnown = true): string
    {
        $dow = ['일', '월', '화', '수', '목', '금', '토'][$opens->dayOfWeek];
        $date = "{$opens->month}월 {$opens->day}일 ({$dow})";
        if (! $timeKnown) {
            return $date;
        }
        $hour = $opens->hour;
        $ampm = $hour < 12 ? '오전' : '오후';
        $h12 = $hour % 12 === 0 ? 12 : $hour % 12;
        $minute = $opens->minute > 0 ? " {$opens->minute}분" : '';

        return "{$date} {$ampm} {$h12}시{$minute}";
    }

    private function platformLabel(string $url): string
    {
        foreach (self::PLATFORMS as $needle => $label) {
            if (str_contains($url, $needle)) {
                return $label;
            }
        }

        return '예매처';
    }

    /** HTML 에서 schema.org Event JSON-LD 블록을 찾는다(스트리밍 SSR 이라 문서 말미에 위치). */
    private function jsonLdEvent(string $html): ?array
    {
        if (! preg_match_all('/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/su', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $block) {
            $decoded = json_decode(html_entity_decode($block, ENT_QUOTES | ENT_HTML5), true);
            foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                if (is_array($item) && ($item['@type'] ?? null) === 'Event') {
                    return $item;
                }
            }
        }

        return null;
    }

    private function ogImage(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/iu', $html, $m)
            || preg_match('/<meta[^>]+content="([^"]+)"[^>]+property="og:image"/iu', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }
}
