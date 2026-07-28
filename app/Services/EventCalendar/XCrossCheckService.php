<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * X(트위터) 크로스체크 — 내한공연 공지 계정(기본 @FstvlLife)의 최근 트윗과
 * 블로그(jpoptistory) 공연의 내한 일정·티켓오픈일을 대조한다.
 * (아티스트 개별 계정 검색은 X 로그인 장벽으로 불가 — 공지 계정 타임라인을 nitter RSS 로 참고.)
 *
 *  - 트윗의 티켓오픈 일시 ↔ 행의 ticket_opens_on: 없으면 채우고(ticket_open_source=x),
 *    다르면 덮지 않고 불일치 기록(extra.xcheck=mismatch — mycon 구조화 데이터 우선), 같으면 확인 표시.
 *  - 트윗의 공연일 ↔ 행 기간: 기간 안이면 일정 교차 확인(extra.xcheck_schedule=ok).
 * nitter 는 가용성이 불안정하므로 실패는 무해 폴백(수집·보강 결과에 영향 없음).
 */
class XCrossCheckService
{
    /**
     * @return array{tweets: int, filled: int, confirmed: int, mismatched: int, skipped: bool}
     */
    public function run(): array
    {
        $cfg = (array) config('event-calendar.x_crosscheck');
        $accounts = (array) ($cfg['accounts'] ?? []);
        $base = rtrim((string) ($cfg['nitter_base'] ?? 'https://nitter.net'), '/');

        $tweets = [];
        foreach ($accounts as $account) {
            $tweets = [...$tweets, ...$this->fetchTweets($base, (string) $account)];
        }
        if ($tweets === []) {
            return ['tweets' => 0, 'filled' => 0, 'confirmed' => 0, 'mismatched' => 0, 'skipped' => true];
        }

        $stats = ['tweets' => count($tweets), 'filled' => 0, 'confirmed' => 0, 'mismatched' => 0, 'skipped' => false];
        $concerts = Event::where('source', 'jpoptistory')
            ->where('active_flg', true)
            ->whereDate('starts_on', '>=', Carbon::today()->subDays(7))
            ->get();

        foreach ($concerts as $event) {
            $titleTokens = EventSyncService::artistTokens($event->title);
            if ($titleTokens === []) {
                continue;
            }
            foreach ($tweets as $tweet) {
                if (array_intersect($titleTokens, EventSyncService::artistTokens($tweet['text'])) === []) {
                    continue;
                }
                $this->applyTweet($event, $tweet, $stats);
            }
        }

        return $stats;
    }

    /** 매칭된 트윗 한 건을 행에 반영한다(오픈일 채움/확인/불일치·일정 교차 확인). */
    private function applyTweet(Event $event, array $tweet, array &$stats): void
    {
        $extra = (array) $event->extra;
        $updates = [];

        // 티켓오픈 문맥의 날짜(연도는 트윗 작성연도 보간)
        $open = $this->parseOpenDate($tweet['text'], $tweet['date']);
        if ($open !== null) {
            if ($event->ticket_opens_on === null) {
                $updates['ticket_opens_on'] = $open['date'];
                $updates['ticket_open_text'] = $open['text'];
                $extra['ticket_open_source'] = 'x';
                $extra['xcheck_url'] = $tweet['url'];
                $stats['filled']++;
            } elseif ($event->ticket_opens_on->toDateString() === $open['date']) {
                if (($extra['xcheck'] ?? null) !== 'ok') {
                    $extra['xcheck'] = 'ok';
                    $extra['xcheck_url'] = $tweet['url'];
                    $stats['confirmed']++;
                }
            } else {
                $extra['xcheck'] = 'mismatch';
                $extra['xcheck_open'] = $open['date'];
                $extra['xcheck_url'] = $tweet['url'];
                $stats['mismatched']++;
                Log::warning('[행사캘린더] X 크로스체크 티켓오픈 불일치', [
                    'event' => $event->title,
                    'ours' => $event->ticket_opens_on->toDateString(),
                    'tweet' => $open['date'],
                    'url' => $tweet['url'],
                ]);
            }
        }

        // 내한 일정 교차 확인: 트윗에 언급된 날짜가 공연 기간 안이면 일정 확인
        foreach ($this->mentionedDates($tweet['text'], $tweet['date']) as $date) {
            $start = $event->starts_on->toDateString();
            $end = $event->ends_on?->toDateString() ?? $start;
            if ($date >= $start && $date <= $end) {
                $extra['xcheck_schedule'] = 'ok';
                break;
            }
        }

        if ($extra !== (array) $event->extra) {
            $updates['extra'] = $extra;
        }
        if ($updates !== []) {
            $event->update($updates);
        }
    }

    /**
     * 트윗에서 티켓오픈 문맥의 날짜를 뽑는다("티켓 오픈: 8월 5일(수) 오후 8시" 류).
     *
     * @return array{date: string, text: string}|null
     */
    private function parseOpenDate(string $text, ?Carbon $tweetedAt): ?array
    {
        if (! preg_match('/(?:티켓|예매)\s*오픈[^0-9]{0,12}(?:(\d{4})\s*년\s*)?(\d{1,2})\s*월\s*(\d{1,2})\s*일\s*(?:\([^)]+\)\s*)?((?:오전|오후|낮|저녁)\s*\d{1,2}\s*시(?:\s*\d{1,2}\s*분)?)?/u', $text, $m)) {
            return null;
        }
        $year = $m[1] !== '' ? (int) $m[1] : ($tweetedAt?->year ?? (int) date('Y'));
        $date = sprintf('%04d-%02d-%02d', $year, $m[2], $m[3]);
        // 연도 미표기 트윗이 연말→연초를 가리키면 이듬해로 보간
        if ($m[1] === '' && $tweetedAt !== null && $date < $tweetedAt->toDateString()) {
            $date = sprintf('%04d-%02d-%02d', $year + 1, $m[2], $m[3]);
        }
        $time = isset($m[4]) && $m[4] !== '' ? ' '.preg_replace('/\s+/u', ' ', trim($m[4])) : '';

        return ['date' => $date, 'text' => "{$m[2]}월 {$m[3]}일{$time}"];
    }

    /**
     * 트윗에 언급된 모든 날짜(연도 보간) — 내한 일정 교차 확인용.
     *
     * @return array<int, string>
     */
    private function mentionedDates(string $text, ?Carbon $tweetedAt): array
    {
        if (! preg_match_all('/(?:(\d{4})\s*년\s*)?(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $text, $m, PREG_SET_ORDER)) {
            return [];
        }
        $dates = [];
        foreach ($m as $match) {
            $year = $match[1] !== '' ? (int) $match[1] : ($tweetedAt?->year ?? (int) date('Y'));
            $date = sprintf('%04d-%02d-%02d', $year, $match[2], $match[3]);
            if ($match[1] === '' && $tweetedAt !== null && $date < $tweetedAt->toDateString()) {
                $date = sprintf('%04d-%02d-%02d', $year + 1, $match[2], $match[3]);
            }
            $dates[] = $date;
        }

        return array_values(array_unique($dates));
    }

    /**
     * nitter RSS 에서 (본문, 작성일, 원문 링크)를 추출한다(SGI TwitterDriver 와 같은 방식).
     *
     * @return array<int, array{text: string, date: ?Carbon, url: string}>
     */
    private function fetchTweets(string $base, string $account): array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('event-calendar.user_agent')])
                ->timeout(20)
                ->get("{$base}/{$account}/rss");
            if (! $res->ok()) {
                return [];
            }
            $xml = $res->body();
        } catch (\Throwable $e) {
            Log::info('[행사캘린더] X 크로스체크 RSS 실패(무해 폴백)', ['account' => $account, 'error' => $e->getMessage()]);

            return [];
        }

        if (! preg_match_all('#<item>(.*?)</item>#s', $xml, $items)) {
            return [];
        }
        $out = [];
        foreach ($items[1] as $item) {
            $raw = preg_match('#<description>(.*?)</description>#s', $item, $m) ? $m[1]
                : (preg_match('#<title>(.*?)</title>#s', $item, $m) ? $m[1] : '');
            $raw = preg_replace('#<!\[CDATA\[(.*?)\]\]>#s', '$1', $raw) ?? $raw;
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '') {
                continue;
            }
            $date = null;
            if (preg_match('#<pubDate>(.*?)</pubDate>#s', $item, $m)) {
                try {
                    $date = Carbon::parse(trim($m[1]))->setTimezone('Asia/Seoul');
                } catch (\Throwable) {
                    $date = null;
                }
            }
            $url = preg_match('#<link>(.*?)</link>#s', $item, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : "https://x.com/{$account}";
            $out[] = ['text' => $text, 'date' => $date, 'url' => $url];
        }

        return $out;
    }
}
