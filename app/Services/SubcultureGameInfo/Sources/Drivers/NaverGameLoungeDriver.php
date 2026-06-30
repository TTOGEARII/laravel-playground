<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\Contracts\CodeSearchDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use App\Services\SubcultureGameInfo\Sources\DTO\CommunitySearchHit;
use Carbon\Carbon;

/**
 * 네이버 게임 라운지 — 한국 게임(블루아카이브/니케/브라운더스트2/트릭컬)의 '공식' 쿠폰 소스.
 * 게임사 GM(운영자)이 라운지 게시판에 쿠폰 코드를 직접 올리므로 가장 신뢰도 높은 메인 소스다.
 *
 * 동작:
 *  1) lounge/{id}/board 로 게시판 목록을 받아 '쿠폰' 게시판(있으면)과 '공지/뉴스' 게시판을 고른다.
 *  2) community/lounge/{id}/feed?boardId= 로 최근 글을 받는다.
 *  3) 본문(네이버 에디터 JSON)을 텍스트로 펴서 '쿠폰 코드 …' 마커 뒤의 코드(대소문자 보존)·보상·
 *     사용기한을 뽑는다. 공지/뉴스 게시판에선 제목에 쿠폰/코드 키워드가 있는 글만 본다.
 */
class NaverGameLoungeDriver extends AbstractSourceDriver implements CodeSearchDriver
{
    private const COUPON_TITLE_KEYWORDS = ['쿠폰', '코드', '리딤', 'coupon', 'redeem'];

    /** 게임당 쿠폰/공지 게시판 글 텍스트 캐시(검증 시 코드마다 재요청 방지). */
    private array $feedTextCache = [];

    /** 코드 마커: 이 문구 바로 뒤의 토큰을 코드로 본다(대소문자 구분 안내문/◈/: 는 건너뜀). */
    private const CODE_MARKER = '쿠폰\s*코드|교환\s*코드|리딤\s*코드|쿠폰코드|coupon\s*code|redeem\s*code';

    public function driverKey(): string
    {
        return 'naver';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.naver');
        $loungeId = $cfg['lounges'][$gameSlug] ?? null;
        if ($loungeId === null) {
            return [];
        }

        $base = rtrim($cfg['base'], '/');
        $boards = $this->pickBoards($base, $loungeId);
        if ($boards === []) {
            return [];
        }

        $region = $this->regionFor($gameSlug);
        $limit = (int) ($cfg['feed_limit'] ?? 20);
        $byCode = [];

        foreach ($boards as [$boardId, $isCouponBoard]) {
            $feeds = $this->getJson("{$base}/community/lounge/{$loungeId}/feed", [
                'boardId' => $boardId,
                'buffFilteringYN' => 'N',
                'limit' => $limit,
                'offset' => 0,
                'order' => 'NEW',
            ]);
            foreach ($feeds['content']['feeds'] ?? [] as $item) {
                $feed = $item['feed'] ?? [];
                $title = (string) ($feed['title'] ?? '');

                // 공지/뉴스 게시판은 쿠폰 관련 제목만(쿠폰 전용 게시판은 전부 본다).
                if (! $isCouponBoard && ! $this->titleLooksCoupon($title)) {
                    continue;
                }

                $body = $this->extractDocumentText((string) ($feed['contents'] ?? ''));
                $code = $this->extractCouponCode($title.' '.$body);
                if ($code === null) {
                    continue;
                }

                $expiresAt = $this->parseKoreanExpiry($title, $body, (string) ($feed['createdDate'] ?? ''));
                $status = $expiresAt === null
                    ? CodeStatus::Active                       // GM 공식 게시 → 기한 없으면 사용가능으로 본다
                    : ($expiresAt->isPast() ? CodeStatus::Expired : CodeStatus::Active);

                $url = "https://game.naver.com/lounge/{$loungeId}/board/{$boardId}";

                // 같은 코드가 여러 글에 보이면 첫(최신) 것 유지.
                $byCode[strtoupper($code)] ??= new CollectedCodeDto(
                    gameSlug: $gameSlug,
                    code: $code,
                    sourceType: SourceType::Aggregator,
                    source: 'naver',
                    region: $region,
                    rewards: $this->extractRewards($body),
                    status: $status,
                    sourceUrl: $url,
                    expiresAt: $expiresAt,
                );
            }
        }

        return array_values($byCode);
    }

    /**
     * 검증용: 코드가 이 게임 공식 라운지의 쿠폰/공지 글에 보이는지 확인한다.
     * 라운지 매핑이 없는 게임(호요버스 등)은 null(검증 skip).
     * 코드가 든 글의 사용기한이 이미 지났으면 expiredHint=true 로 만료를 알린다.
     */
    public function searchCode(string $gameSlug, string $code): ?CommunitySearchHit
    {
        $cfg = config('subculture-game-info.drivers.naver');
        $loungeId = $cfg['lounges'][$gameSlug] ?? null;
        if ($loungeId === null) {
            return null;
        }

        $url = "https://game.naver.com/lounge/{$loungeId}/home";
        $found = false;
        $recentAt = null;
        $expiredHint = false;

        foreach ($this->loungeFeedTexts($loungeId) as $post) {
            if (mb_stripos($post['title'].' '.$post['body'], $code) === false) {
                continue;
            }
            $found = true;
            $created = $this->parseCreatedDate($post['date']);
            if ($created !== null && ($recentAt === null || $created->gt($recentAt))) {
                $recentAt = $created;
            }
            // 공식 글의 사용기한이 지났으면 그 코드는 만료로 본다.
            $expiry = $this->parseKoreanExpiry($post['title'], $post['body'], $post['date']);
            if ($expiry !== null && $expiry->isPast()) {
                $expiredHint = true;
            }
        }

        return new CommunitySearchHit($found, 'naver-search', $url, $recentAt, $expiredHint);
    }

    /**
     * 라운지 쿠폰/공지 게시판 글의 (제목, 본문, 작성일)을 게임당 1회만 받아 캐시한다.
     *
     * @return array<int, array{title:string, body:string, date:string}>
     */
    private function loungeFeedTexts(string $loungeId): array
    {
        if (isset($this->feedTextCache[$loungeId])) {
            return $this->feedTextCache[$loungeId];
        }

        $base = rtrim(config('subculture-game-info.drivers.naver.base'), '/');
        $limit = (int) config('subculture-game-info.drivers.naver.feed_limit', 20);
        $posts = [];

        foreach ($this->pickBoards($base, $loungeId) as [$boardId, $isCouponBoard]) {
            $feeds = $this->getJson("{$base}/community/lounge/{$loungeId}/feed", [
                'boardId' => $boardId,
                'buffFilteringYN' => 'N',
                'limit' => $limit,
                'offset' => 0,
                'order' => 'NEW',
            ]);
            foreach ($feeds['content']['feeds'] ?? [] as $item) {
                $feed = $item['feed'] ?? [];
                $title = (string) ($feed['title'] ?? '');
                if (! $isCouponBoard && ! $this->titleLooksCoupon($title)) {
                    continue;
                }
                $posts[] = [
                    'title' => $title,
                    'body' => $this->extractDocumentText((string) ($feed['contents'] ?? '')),
                    'date' => (string) ($feed['createdDate'] ?? ''),
                ];
            }
        }

        return $this->feedTextCache[$loungeId] = $posts;
    }

    /** 라운지 작성일(yyyyMMddHHmmss) → Carbon. 실패 시 null. */
    private function parseCreatedDate(string $createdDate): ?Carbon
    {
        if (! preg_match('/^\d{8}/', $createdDate)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('YmdHis', str_pad(substr($createdDate, 0, 14), 14, '0'), config('app.timezone', 'Asia/Seoul'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 게시판 목록에서 (boardId, 쿠폰전용여부) 쌍을 고른다.
     * '쿠폰'이 든 게시판(단, 'IOS 쿠폰 입력' 같은 입력안내 게시판 제외)은 쿠폰전용,
     * '공지'/'뉴스'가 든 게시판은 보조(제목 필터).
     *
     * @return array<int, array{0:int, 1:bool}>
     */
    private function pickBoards(string $base, string $loungeId): array
    {
        $json = $this->getJson("{$base}/lounge/{$loungeId}/board");
        if (! is_array($json)) {
            return [];
        }

        $boards = [];
        $this->eachBoard($json['content'] ?? $json, function (int $id, string $name) use (&$boards) {
            $isCoupon = (mb_strpos($name, '쿠폰') !== false || mb_strpos($name, '코드') !== false)
                && mb_strpos($name, '입력') === false && mb_stripos($name, 'ios') === false;
            $isNotice = mb_strpos($name, '공지') !== false || mb_strpos($name, '뉴스') !== false;
            if ($isCoupon) {
                $boards[$id] = true;
            } elseif ($isNotice && ! isset($boards[$id])) {
                $boards[$id] = false;
            }
        });

        // 쿠폰전용 게시판을 앞에 둔다(코드 우선 확보).
        $result = [];
        foreach ($boards as $id => $isCoupon) {
            if ($isCoupon) {
                $result[] = [$id, true];
            }
        }
        foreach ($boards as $id => $isCoupon) {
            if (! $isCoupon) {
                $result[] = [$id, false];
            }
        }

        return $result;
    }

    /** board 목록 JSON(중첩 가능)을 순회하며 (boardId, boardName) 콜백. */
    private function eachBoard(mixed $node, callable $cb): void
    {
        if (is_array($node)) {
            if (isset($node['boardId']) && is_numeric($node['boardId'])) {
                $name = (string) ($node['boardName'] ?? $node['name'] ?? '');
                if ($name !== '') {
                    $cb((int) $node['boardId'], $name);
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $this->eachBoard($child, $cb);
                }
            }
        }
    }

    private function titleLooksCoupon(string $title): bool
    {
        foreach (self::COUPON_TITLE_KEYWORDS as $kw) {
            if (mb_stripos($title, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 네이버 에디터 document JSON 을 평문 텍스트로 편다(코드/보상/기한이 본문 텍스트에 있음). */
    private function extractDocumentText(string $contents): string
    {
        $contents = trim($contents);
        if ($contents === '') {
            return '';
        }
        if ($contents[0] !== '{' && $contents[0] !== '[') {
            return $this->stripToText($contents);  // 평문/HTML
        }
        $doc = json_decode($contents, true);
        if (! is_array($doc)) {
            return $this->stripToText($contents);
        }

        $texts = [];
        $walk = function ($node) use (&$walk, &$texts) {
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

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $texts)));
    }

    /** '쿠폰 코드 …' 마커 뒤의 첫 코드형 토큰(대소문자 보존). 없으면 null. */
    private function extractCouponCode(string $text): ?string
    {
        $re = '/(?:'.self::CODE_MARKER.')\s*(?:\(\s*대소문자[^)]*\))?\s*[◈*:\-\s]*([A-Za-z0-9]{4,20})/u';
        if (! preg_match($re, $text, $m)) {
            return null;
        }
        $code = $m[1];
        if (in_array(strtoupper($code), static::CODE_DENYLIST, true)) {
            return null;
        }
        // 순수 숫자(연도/날짜 등)는 제외.
        if (preg_match('/^\d+$/', $code)) {
            return null;
        }

        return $code;
    }

    /** '쿠폰 보상' 마커 뒤 텍스트를 간단 정리해 보상 문자열로(인트로 '보상을…' 오매칭 방지). */
    private function extractRewards(string $text): ?string
    {
        if (! preg_match('/(?:쿠폰\s*보상|보상\s*내역)\s*[◈*:\-\s]*(.{4,120}?)(?:🗓|사용\s*기한|쿠폰\s*입력|입력\s*방법|$)/u', $text, $m)) {
            return null;
        }
        $rewards = trim((string) preg_replace('/[◈*\-]+/u', ' ', $m[1]));
        $rewards = trim((string) preg_replace('/\s+/u', ' ', $rewards));

        return $rewards !== '' ? mb_substr($rewards, 0, 120) : null;
    }

    /**
     * 한국식 '~ M월 D일' 사용기한 파싱. 연도는 글 작성일(createdDate: yyyyMMdd...)에서 추론하고,
     * 만료월이 작성월보다 많이 작으면 연말→연초 롤오버로 +1년.
     */
    private function parseKoreanExpiry(string $title, string $body, string $createdDate): ?Carbon
    {
        $year = strlen($createdDate) >= 4 ? (int) substr($createdDate, 0, 4) : (int) now()->year;
        $createdMonth = strlen($createdDate) >= 6 ? (int) substr($createdDate, 4, 2) : 0;
        $tz = config('app.timezone', 'Asia/Seoul');

        // 제목의 '(~6월 28일)' 가 가장 신뢰도 높음. 없으면 본문의 마지막 'M월 D일'(종료일).
        if (preg_match('/~\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $title, $m)
            || preg_match_all('/(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $body, $all, PREG_SET_ORDER) && ($m = end($all))) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }
            if ($createdMonth > 0 && $month < $createdMonth - 1) {
                $year++;  // 연말 작성 → 다음 해 만료
            }
            try {
                return Carbon::create($year, $month, $day, 23, 59, 59, $tz);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
