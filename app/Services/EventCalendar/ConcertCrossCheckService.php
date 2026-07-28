<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 내한공연 크로스체크 — 블로그(jpoptistory) 공연을 "그 가수의 X"와 "구글 검색(뉴스)"으로 교차 확인한다.
 *
 *  ① 가수명 → 공식 X 핸들: MusicBrainz 공개 API(url-rels 의 twitter/x 링크, 키 불필요·1req/s).
 *     핸들은 30일 캐시(못 찾은 것도 캐시해 재조회 방지), 행에는 extra.x_handle 기록.
 *  ② 가수 X 타임라인(nitter RSS): 서울/韓国/내한 신호 트윗의 날짜로 내한 일정 교차 확인,
 *     티켓 문맥(티켓/チケット×오픈/発売) 날짜로 티켓오픈 대조 — 신규 공지 시점에 유효(RSS 는 최근 트윗만).
 *  ③ 구글 뉴스 RSS 폴백("{가수} 내한"): X 에서 확인 못 한 공연은 뉴스 기사 제목·날짜로 대조
 *     (검색엔진 본검색은 봇 차단이라 뉴스 RSS 가 열려 있는 유일한 구글 창구 — 실측).
 *
 * 반영 규칙: 없는 티켓오픈일은 채우고(extra.ticket_open_source=x|news), mycon 확보값과 다르면
 * 덮지 않고 불일치 기록(extra.xcheck=mismatch·로그), 공연 기간 내 날짜 확인은 extra.xcheck_schedule=ok.
 * 모든 외부 실패는 무해 폴백(수집·보강에 영향 없음).
 */
class ConcertCrossCheckService
{
    private const HANDLE_CACHE_DAYS = 30;

    /** 제목 절단 키워드(이 단어부터는 공연명 — 앞부분이 가수명). 한국어는 접두 매칭. */
    private const CUT_WORDS = [
        'asia', 'world', 'japan', 'live', 'tour', 'concert', 'oneman', 'one-man', 'fan', 'fanmeeting',
        'birthday', 'in', '내한', '단독', '콘서트', '공연', '팬미팅', '아시아', '투어', '라이브', '조인트',
    ];

    /**
     * @return array{concerts: int, handles: int, tweets: int, articles: int, filled: int, confirmed: int, schedule_ok: int, mismatched: int}
     */
    public function run(): array
    {
        $cfg = (array) config('event-calendar.crosscheck');
        $stats = ['concerts' => 0, 'handles' => 0, 'tweets' => 0, 'articles' => 0, 'filled' => 0, 'confirmed' => 0, 'schedule_ok' => 0, 'mismatched' => 0];

        $concerts = Event::where('source', 'jpoptistory')
            ->where('active_flg', true)
            ->whereDate('starts_on', '>=', Carbon::today()->subDays(7))
            ->get();

        $timelineMemo = [];
        foreach ($concerts as $event) {
            $stats['concerts']++;
            $artist = self::artistName($event->title);
            if ($artist === '') {
                continue;
            }

            // ① 가수의 X: 핸들 확보 → 타임라인 신호
            $signals = [];
            $handle = ($cfg['musicbrainz'] ?? true) ? $this->resolveHandle($artist, $event) : null;
            if ($handle !== null) {
                $stats['handles']++;
                $timelineMemo[$handle] ??= $this->fetchTweets((string) ($cfg['nitter_base'] ?? 'https://nitter.net'), $handle);
                $stats['tweets'] += count($timelineMemo[$handle]);
                $signals = $this->extractSignals($timelineMemo[$handle], 'x');
            }

            // ③ 구글 뉴스 폴백 — X 에서 신호를 못 얻었고 아직 확인이 필요한 공연만
            if ($signals === [] && ($cfg['news'] ?? true) && $this->needsCheck($event)) {
                $articles = $this->fetchNews($artist);
                $stats['articles'] += count($articles);
                $signals = $this->extractSignals($articles, 'news');
            }

            if ($signals !== []) {
                $this->applySignals($event, $signals, $stats);
            }
        }

        return $stats;
    }

    /** 아직 교차 확인이 안 됐거나 티켓오픈이 없는 공연인가(뉴스 폴백 대상). */
    private function needsCheck(Event $event): bool
    {
        $extra = (array) $event->extra;

        return $event->ticket_opens_on === null || ($extra['xcheck_schedule'] ?? null) !== 'ok';
    }

    /**
     * 공연 제목에서 가수명 추출 — 절단 키워드/연도 앞까지(괄호 부제 제거, 선행 연도 스킵).
     */
    public static function artistName(string $title): string
    {
        $stripped = preg_replace('/[（(【\[].*$/u', '', $title) ?? $title;
        $tokens = preg_split('/\s+/u', trim($stripped)) ?: [];
        $picked = [];
        foreach ($tokens as $token) {
            $bare = mb_strtolower(trim($token, "\"'“”‘’~〜-–—:："));
            if ($bare === '' || preg_match('/^20\d{2}$/', $bare)) {
                if ($picked !== []) {
                    break; // 뒤쪽 연도는 절단
                }

                continue; // 선행 연도("2026 카즈미…")는 스킵
            }
            $isCut = false;
            foreach (self::CUT_WORDS as $cut) {
                if ($bare === $cut || (preg_match('/^\p{Hangul}/u', $cut) && str_starts_with($bare, $cut))) {
                    $isCut = true;
                    break;
                }
            }
            if ($isCut && $picked !== []) {
                break;
            }
            if (! $isCut) {
                $picked[] = $token;
            }
            if (count($picked) >= 4) {
                break; // 가수명이 4어절을 넘는 일은 드묾 — 폭주 방지
            }
        }
        if ($picked === []) {
            $picked = array_slice($tokens, 0, 1);
        }

        return trim(implode(' ', $picked));
    }

    /** MusicBrainz 로 가수명 → 공식 X 핸들(30일 캐시, 실패도 캐시). */
    public function resolveHandle(string $artist, Event $event): ?string
    {
        $extra = (array) $event->extra;
        if (array_key_exists('x_handle', $extra)) {
            return $extra['x_handle'] ?: null;
        }

        $cacheKey = 'ec:xhandle:'.md5(mb_strtolower($artist));
        $handle = Cache::remember($cacheKey, now()->addDays(self::HANDLE_CACHE_DAYS), function () use ($artist) {
            return (string) ($this->lookupMusicBrainz($artist) ?? '');
        });
        $handle = $handle !== '' ? $handle : null;

        $event->update(['extra' => $extra + ['x_handle' => $handle ?? '']]);

        return $handle;
    }

    private function lookupMusicBrainz(string $artist): ?string
    {
        $headers = ['User-Agent' => 'laravel-playland-event-calendar/1.0 (cagameku3842@gmail.com)'];
        try {
            $search = Http::withHeaders($headers)->timeout(15)
                ->get('https://musicbrainz.org/ws/2/artist/', ['query' => 'artist:"'.$artist.'"', 'fmt' => 'json', 'limit' => 1]);
            $found = $search->ok() ? ($search->json('artists.0') ?? null) : null;
            if ($found === null || (int) ($found['score'] ?? 0) < 85) {
                return null; // 확신 없는 매칭은 버린다(엉뚱한 가수 타임라인 방지)
            }
            usleep(1100000); // MusicBrainz 레이트리밋(1req/s) 준수

            $detail = Http::withHeaders($headers)->timeout(15)
                ->get("https://musicbrainz.org/ws/2/artist/{$found['id']}", ['inc' => 'url-rels', 'fmt' => 'json']);
            usleep(1100000);
            foreach ((array) $detail->json('relations') as $relation) {
                $url = (string) data_get($relation, 'url.resource', '');
                if (preg_match('#(?:twitter|x)\.com/([A-Za-z0-9_]{2,15})$#', $url, $m)) {
                    return $m[1];
                }
            }
        } catch (\Throwable $e) {
            Log::info('[행사캘린더] MusicBrainz 조회 실패(무해)', ['artist' => $artist, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * 타임라인/기사에서 (내한 신호 + 날짜) 시그널을 뽑는다.
     *
     * @param  array<int, array{text: string, date: ?Carbon, url: string}>  $posts
     * @return array<int, array{kind: string, date: string, source: string, url: string, text: string}>
     */
    private function extractSignals(array $posts, string $source): array
    {
        $signals = [];
        foreach ($posts as $post) {
            $text = $post['text'];
            // 내한 신호가 없는 글은 통째로 무시(가수 타임라인의 일본 국내 공지 노이즈 제거)
            if (! preg_match('/내한|서울|SEOUL|Seoul|ソウル|韓国|KOREA|Korea/u', $text)) {
                continue;
            }
            $open = $this->parseOpenDate($text, $post['date']);
            if ($open !== null) {
                $signals[] = ['kind' => 'open', 'date' => $open['date'], 'source' => $source, 'url' => $post['url'], 'text' => $open['text']];
            }
            foreach ($this->mentionedDates($text, $post['date']) as $date) {
                $signals[] = ['kind' => 'date', 'date' => $date, 'source' => $source, 'url' => $post['url'], 'text' => ''];
            }
        }

        return $signals;
    }

    /** 시그널을 행에 반영: 오픈일 채움/확인/불일치 + 내한 일정 교차 확인. */
    private function applySignals(Event $event, array $signals, array &$stats): void
    {
        $extra = (array) $event->extra;
        $updates = [];

        foreach ($signals as $signal) {
            if ($signal['kind'] === 'open') {
                if ($event->ticket_opens_on === null && ! isset($updates['ticket_opens_on'])) {
                    $updates['ticket_opens_on'] = $signal['date'];
                    $updates['ticket_open_text'] = $signal['text'];
                    $extra['ticket_open_source'] = $signal['source'];
                    $extra['xcheck_url'] = $signal['url'];
                    $stats['filled']++;
                } elseif (($event->ticket_opens_on?->toDateString() ?? $updates['ticket_opens_on'] ?? null) === $signal['date']) {
                    if (($extra['xcheck'] ?? null) !== 'ok') {
                        $extra['xcheck'] = 'ok';
                        $extra['xcheck_url'] = $signal['url'];
                        $stats['confirmed']++;
                    }
                } else {
                    $extra['xcheck'] = 'mismatch';
                    $extra['xcheck_open'] = $signal['date'];
                    $extra['xcheck_url'] = $signal['url'];
                    $stats['mismatched']++;
                    Log::warning('[행사캘린더] 크로스체크 티켓오픈 불일치', [
                        'event' => $event->title, 'ours' => $event->ticket_opens_on?->toDateString(),
                        'found' => $signal['date'], 'source' => $signal['source'], 'url' => $signal['url'],
                    ]);
                }

                continue;
            }
            // 내한 일정 교차 확인: 언급 날짜가 공연 기간 안이면 확인
            $start = $event->starts_on->toDateString();
            $end = $event->ends_on?->toDateString() ?? $start;
            if ($signal['date'] >= $start && $signal['date'] <= $end && ($extra['xcheck_schedule'] ?? null) !== 'ok') {
                $extra['xcheck_schedule'] = 'ok';
                $extra['xcheck_schedule_source'] = $signal['source'];
                $stats['schedule_ok']++;
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
     * 티켓오픈 문맥의 날짜("티켓 오픈: 8월 5일 오후 8시" / "チケット発売：9/1(月)").
     *
     * @return array{date: string, text: string}|null
     */
    private function parseOpenDate(string $text, ?Carbon $postedAt): ?array
    {
        if (! preg_match('/(?:티켓|예매|チケット|チケ)[^0-9]{0,14}(?:오픈|発売|発売開始|オープン|일반|open)?[^0-9]{0,12}(?:(\d{4})\s*[년\/\.]\s*)?(\d{1,2})\s*[월月\/\.]\s*(\d{1,2})\s*[일日]?\s*(?:\([^)]+\)\s*)?((?:오전|오후|낮|저녁)\s*\d{1,2}\s*시(?:\s*\d{1,2}\s*분)?|\d{1,2}:\d{2})?/u', $text, $m)) {
            return null;
        }
        $date = $this->interpolateYear((int) $m[2], (int) $m[3], $m[1] !== '' ? (int) $m[1] : null, $postedAt);
        $time = isset($m[4]) && $m[4] !== '' ? ' '.preg_replace('/\s+/u', ' ', trim($m[4])) : '';

        return ['date' => $date, 'text' => "{$m[2]}월 {$m[3]}일{$time}"];
    }

    /**
     * 글에 언급된 날짜들(M월 D일·M/D·M月D日, 연도 보간) — 내한 일정 대조용.
     *
     * @return array<int, string>
     */
    private function mentionedDates(string $text, ?Carbon $postedAt): array
    {
        if (! preg_match_all('/(?:(\d{4})\s*[년\/\.]\s*)?(\d{1,2})\s*[월月\/\.]\s*(\d{1,2})\s*[일日]?/u', $text, $m, PREG_SET_ORDER)) {
            return [];
        }
        $dates = [];
        foreach ($m as $match) {
            $monthNum = (int) $match[2];
            $dayNum = (int) $match[3];
            if ($monthNum < 1 || $monthNum > 12 || $dayNum < 1 || $dayNum > 31) {
                continue; // "10:00" 같은 시각 오탐 방지
            }
            $dates[] = $this->interpolateYear($monthNum, $dayNum, $match[1] !== '' ? (int) $match[1] : null, $postedAt);
        }

        return array_values(array_unique($dates));
    }

    /** 연도 미표기 날짜는 작성일 연도로, 작성일보다 과거가 되면 이듬해로 보간. */
    private function interpolateYear(int $month, int $day, ?int $year, ?Carbon $postedAt): string
    {
        $resolved = $year ?? ($postedAt?->year ?? (int) date('Y'));
        $date = sprintf('%04d-%02d-%02d', $resolved, $month, $day);
        if ($year === null && $postedAt !== null && $date < $postedAt->toDateString()) {
            $date = sprintf('%04d-%02d-%02d', $resolved + 1, $month, $day);
        }

        return $date;
    }

    /**
     * nitter RSS 타임라인 → (본문, 작성일, 링크). 실패는 빈 배열(무해).
     *
     * @return array<int, array{text: string, date: ?Carbon, url: string}>
     */
    private function fetchTweets(string $base, string $handle): array
    {
        return $this->fetchRssItems(rtrim($base, '/')."/{$handle}/rss", "https://x.com/{$handle}");
    }

    /**
     * 구글 뉴스 RSS 검색("{가수} 내한") → 기사 (제목, 날짜, 링크).
     *
     * @return array<int, array{text: string, date: ?Carbon, url: string}>
     */
    private function fetchNews(string $artist): array
    {
        $url = 'https://news.google.com/rss/search?q='.rawurlencode("{$artist} 내한").'&hl=ko&gl=KR&ceid=KR:ko';

        return $this->fetchRssItems($url, 'https://news.google.com');
    }

    /** @return array<int, array{text: string, date: ?Carbon, url: string}> */
    private function fetchRssItems(string $url, string $fallbackLink): array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('event-calendar.user_agent')])
                ->timeout(20)
                ->get($url);
            if (! $res->ok()) {
                return [];
            }
            $xml = $res->body();
        } catch (\Throwable $e) {
            Log::info('[행사캘린더] 크로스체크 RSS 실패(무해)', ['url' => $url, 'error' => $e->getMessage()]);

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
            $link = preg_match('#<link>(.*?)</link>#s', $item, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : $fallbackLink;
            $out[] = ['text' => $text, 'date' => $date, 'url' => $link];
        }

        return $out;
    }
}
