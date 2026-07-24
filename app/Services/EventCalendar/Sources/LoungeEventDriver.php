<?php

namespace App\Services\EventCalendar\Sources;

use App\Services\EventCalendar\Sources\Concerns\FetchesHtml;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 네이버 게임 라운지 공지에서 오프라인 행사(팝업스토어·콜라보 카페·오케스트라)를 수집.
 * 전시장 캘린더에 안 잡히는 게임사 행사를 커버(정찰 실증: 니케 여름 팝업스토어 공지 시리즈).
 *
 * comm-api(브라우저 UA HTTP, Playwright 불필요): /community/lounge/{id}/feed?boardId= (limit 상한 20).
 * 본문(contents)은 네이버 에디터 JSON 문서라 text/value/caption 값만 모아 텍스트화한다.
 * 같은 행사의 연속 공지(사전 안내→현장 안내→입장 안내)는 "기간" 파싱 결과가 같으므로
 * (라운지, 기간) 키로 중복 제거하고 최신 공지를 남긴다.
 */
class LoungeEventDriver implements EventSource
{
    use FetchesHtml;

    public function __construct(private VenueEventFilter $filter) {}

    public function code(): string
    {
        return 'lounge';
    }

    public function collect(array $skipKeys = []): array
    {
        $cfg = (array) config('event-calendar.sources.lounge');
        $base = rtrim((string) ($cfg['base'] ?? ''), '/');
        $keywords = (array) ($cfg['keywords'] ?? []);
        $skip = array_flip($skipKeys);

        $excludes = (array) ($cfg['exclude_keywords'] ?? []);

        $byRange = [];
        foreach ((array) ($cfg['lounges'] ?? []) as $lounge) {
            $posts = $this->feedPosts($base, (string) $lounge['lounge'], (int) $lounge['board']);
            foreach ($posts as $post) {
                if (! $this->titleMatches($post['title'], $keywords) || $this->titleMatches($post['title'], $excludes)) {
                    continue; // 신호 키워드 미포함 or 온라인 상영류 제외
                }
                [$startsOn, $endsOn] = $this->parseRangeWithYear($post['body'], $post['createdAt']);
                if ($startsOn === null) {
                    Log::info('라운지 행사 기간 파싱 불가 — 스킵', ['lounge' => $lounge['lounge'], 'title' => $post['title']]);

                    continue;
                }
                $key = 'lounge-'.$lounge['lounge'].'-'.$post['feedId'];
                if (isset($skip[$key])) {
                    continue;
                }

                $clean = $this->cleanTitle($post['title']);
                // 원제목에 이미 게임명이 있으면 라벨 접두를 붙이지 않는다("니케 <승리의 여신: 니케>…" 중복 방지)
                $title = mb_stripos($clean, (string) $lounge['label']) !== false
                    ? $clean
                    : trim($lounge['label'].' '.$clean);
                $venue = preg_match('/장\s*소\s*[:：]\s*([^\n|]{2,80})/u', $post['body'], $m) ? trim($m[1]) : null;

                // 같은 (라운지, 기간)의 연속 공지는 최신(피드 순서상 먼저 온) 것만 유지
                $rangeKey = $lounge['lounge'].'|'.$startsOn.'|'.($endsOn ?? '');
                if (isset($byRange[$rangeKey])) {
                    continue;
                }
                $byRange[$rangeKey] = new CollectedEventData(
                    source: $this->code(),
                    externalKey: $key,
                    kind: $this->filter->kindFor($title),
                    title: $title,
                    startsOn: $startsOn,
                    endsOn: $endsOn,
                    venue: $venue,
                    extra: array_filter(['lounge' => $lounge['lounge'], 'notice_title' => $post['title']]),
                    detailUrl: "https://game.naver.com/lounge/{$lounge['lounge']}/board/{$lounge['board']}",
                );
            }
        }

        // 연속 공지 2차 중복 제거: 같은 라운지에서 다른 이벤트 제목을 포함하는 제목(예: "…팝업스토어 입장 퀴즈"
        // ⊃ "…팝업스토어")은 부속 공지 — 기간이 달라 rangeKey 로 못 잡은 것을 제목 포함관계로 걸러낸다.
        $events = array_values($byRange);
        $kept = [];
        foreach ($events as $e) {
            $isSub = false;
            foreach ($events as $other) {
                if ($other !== $e
                    && ($other->extra['lounge'] ?? null) === ($e->extra['lounge'] ?? null)
                    && mb_strlen($other->title) < mb_strlen($e->title)
                    && mb_stripos($e->title, $other->title) !== false) {
                    $isSub = true;

                    break;
                }
            }
            if (! $isSub) {
                $kept[] = $e;
            }
        }

        return $kept;
    }

    /** @return array<int, array{feedId: string, title: string, body: string, createdAt: string}> */
    private function feedPosts(string $base, string $loungeId, int $boardId): array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('event-calendar.user_agent')])
                ->timeout(15)
                ->get("{$base}/community/lounge/{$loungeId}/feed", [
                    'boardId' => $boardId,
                    'buffFilteringYN' => 'N',
                    'limit' => 20, // API 상한(21 이상이면 빈 결과 — 정찰 실측)
                    'offset' => 0,
                    'order' => 'NEW',
                ]);
            if (! $res->ok()) {
                return [];
            }

            $posts = [];
            foreach ($res->json('content.feeds') ?? [] as $item) {
                $feed = $item['feed'] ?? [];
                $posts[] = [
                    'feedId' => (string) ($feed['feedId'] ?? ($feed['id'] ?? md5((string) ($feed['title'] ?? '')))),
                    'title' => (string) ($feed['title'] ?? ''),
                    'body' => $this->documentText((string) ($feed['contents'] ?? '')),
                    'createdAt' => (string) ($feed['createdDate'] ?? ($feed['createdAt'] ?? '')),
                ];
            }

            return $posts;
        } catch (\Throwable $e) {
            Log::warning('라운지 피드 요청 실패', ['lounge' => $loungeId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function titleMatches(string $title, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (mb_stripos($title, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 공지 제목 정리 — 엔티티 디코드·장식 괄호(『』【】)·"사전/현장/입장 안내" 꼬리 제거. */
    private function cleanTitle(string $title): string
    {
        $t = html_entity_decode($title, ENT_QUOTES | ENT_HTML5); // &#x1f3bc; 같은 이모지 엔티티
        $t = preg_replace('/[『』【】\[\]]/u', ' ', $t);
        $t = preg_replace('/(사전|현장|입장|이용)?\s*(안내|공지)\s*$/u', '', trim($t));

        return trim(preg_replace('/\s+/u', ' ', $t)) ?: $title;
    }

    /**
     * 본문에서 행사 기간을 파싱("7월 10일(금) ~ 7월 27일(월)" — 연도 없으면 작성일 기준 보간).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseRangeWithYear(string $body, string $createdAt): array
    {
        $createdYear = preg_match('/^(\d{4})/', $createdAt, $y) ? (int) $y[1] : (int) date('Y');
        $createdMonth = preg_match('/^\d{4}[.\-](\d{1,2})/', $createdAt, $mo) ? (int) $mo[1] : (int) date('n');

        if (! preg_match_all('/(?:(\d{4})\s*[.년]\s*)?(\d{1,2})\s*[.월]\s*(\d{1,2})\s*일?/u', $body, $m, PREG_SET_ORDER)) {
            return [null, null];
        }
        $dates = [];
        foreach (array_slice($m, 0, 4) as $d) {
            $year = $d[1] !== '' ? (int) $d[1] : $createdYear;
            // 연도 미기재 + 작성월보다 한참 이른 달(예: 12월 작성 → 1월 행사)은 이듬해로 보간
            if ($d[1] === '' && (int) $d[2] < $createdMonth - 6) {
                $year++;
            }
            $dates[] = sprintf('%04d-%02d-%02d', $year, $d[2], $d[3]);
        }
        $start = min($dates);
        $end = max($dates);

        return [$start, $end !== $start ? $end : null];
    }

    /** 네이버 에디터 JSON 문서에서 보이는 텍스트만 수집(기존 SGI 드라이버 방식 축약). */
    private function documentText(string $contents): string
    {
        $contents = trim($contents);
        if ($contents === '' || ($contents[0] !== '{' && $contents[0] !== '[')) {
            return $this->textOf($contents);
        }
        $doc = json_decode($contents, true);
        if (! is_array($doc)) {
            return $this->textOf($contents);
        }
        $texts = [];
        $walk = function ($node) use (&$walk, &$texts): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_string($value) && in_array($key, ['text', 'value', 'caption'], true)) {
                    $texts[] = $value;
                } else {
                    $walk($value);
                }
            }
        };
        $walk($doc);

        return trim(preg_replace('/\s+/u', ' ', implode("\n", $texts)));
    }
}
