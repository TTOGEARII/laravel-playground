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
 * 네이버 게임 라운지 — 한국 게임(블루아카이브/니케/브라운더스트2/트릭컬/명조)의 '공식' 쿠폰 소스.
 * 게임사 GM(운영자)이 라운지 게시판에 쿠폰 코드를 직접 올리므로 가장 신뢰도 높은 메인 소스다.
 *
 * 두 가지 역할:
 *  - collect()    : 라운지 글에서 쿠폰 코드를 '수집'(SourceDriver).
 *  - searchCode() : 다른 곳에서 가져온 코드가 라운지 글에 있는지 '검증'(CodeSearchDriver).
 * 둘 다 loungePosts() 가 받아온 게시글(제목·본문·작성일)을 본다.
 *
 * 수집 흐름:
 *  1) lounge/{id}/board       → 게시판 목록에서 '쿠폰' 게시판(있으면)·'공지/뉴스' 게시판을 고른다.
 *  2) community/lounge/{id}/feed?boardId=  → 게시판별 최근 글을 받는다.
 *  3) 본문(네이버 에디터 JSON)을 평문으로 펴서 '쿠폰 코드 …' 마커 뒤의 코드(대소문자 보존)·보상·
 *     사용기한을 뽑는다. 공지/뉴스 게시판은 제목에 쿠폰/코드 키워드가 있는 글만 본다.
 */
class NaverGameLoungeDriver extends AbstractSourceDriver implements CodeSearchDriver
{
    /** 라운지 글 한 건당 받을 최근 글 수(게시판별). */
    private const FEED_LIMIT_FALLBACK = 20;

    /** 공지/뉴스 게시판에서 '쿠폰 글'로 인정할 제목 키워드. */
    private const COUPON_TITLE_KEYWORDS = ['쿠폰', '코드', '리딤', 'coupon', 'redeem'];

    /** 코드 마커: 이 문구 바로 뒤의 토큰을 코드로 본다(뒤의 '대소문자 구분' 안내문/◈/: 는 건너뜀). */
    private const CODE_MARKER = '쿠폰\s*코드|교환\s*코드|리딤\s*코드|쿠폰코드|coupon\s*code|redeem\s*code';

    /** 게임(라운지)당 게시글 목록 캐시 — 수집/검증이 같은 글을 두 번 받지 않도록. */
    private array $postsCache = [];

    public function driverKey(): string
    {
        return 'naver';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $loungeId = $this->loungeId($gameSlug);
        if ($loungeId === null) {
            return [];
        }

        $region = $this->regionFor($gameSlug);
        $byCode = [];

        foreach ($this->loungePosts($loungeId) as $post) {
            $code = $this->extractCouponCode($post['title'].' '.$post['body']);
            if ($code === null) {
                continue;
            }

            $expiresAt = $this->parseKoreanExpiry($post['title'], $post['body'], $post['date']);
            // GM 공식 게시물이므로, 기한이 없으면 사용가능·기한이 지났으면 만료로 본다.
            $status = ($expiresAt !== null && $expiresAt->isPast()) ? CodeStatus::Expired : CodeStatus::Active;

            // 같은 코드가 여러 글에 보이면 첫(최신) 글 것을 유지한다.
            $byCode[strtoupper($code)] ??= new CollectedCodeDto(
                gameSlug: $gameSlug,
                code: $code,
                sourceType: SourceType::Aggregator,
                source: 'naver',
                region: $region,
                rewards: $this->extractRewards($post['body']),
                status: $status,
                sourceUrl: "https://game.naver.com/lounge/{$loungeId}/board/{$post['boardId']}",
                expiresAt: $expiresAt,
            );
        }

        return array_values($byCode);
    }

    /**
     * 검증용: 코드가 이 게임 공식 라운지의 쿠폰/공지 글에 보이는지 확인한다.
     * 라운지 매핑이 없는 게임(호요버스 등)은 null 반환(검증 skip).
     * 코드가 든 글의 사용기한이 이미 지났으면 expiredHint=true 로 만료를 알린다.
     */
    public function searchCode(string $gameSlug, string $code): ?CommunitySearchHit
    {
        $loungeId = $this->loungeId($gameSlug);
        if ($loungeId === null) {
            return null;
        }

        $found = false;
        $recentAt = null;
        $expiredHint = false;

        foreach ($this->loungePosts($loungeId) as $post) {
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

        return new CommunitySearchHit($found, 'naver-search', "https://game.naver.com/lounge/{$loungeId}/home", $recentAt, $expiredHint);
    }

    /** 게임 슬러그 → 네이버 라운지 ID(매핑 없으면 null). */
    private function loungeId(string $gameSlug): ?string
    {
        return config("subculture-game-info.drivers.naver.lounges.{$gameSlug}");
    }

    /**
     * 라운지 쿠폰/공지 게시판 글을 받아 (게시판ID, 제목, 본문, 작성일)로 정리한다.
     * 게임당 1회만 받아 캐시하므로 수집·검증이 같은 글을 중복 요청하지 않는다.
     *
     * @return array<int, array{boardId:int, title:string, body:string, date:string}>
     */
    private function loungePosts(string $loungeId): array
    {
        if (isset($this->postsCache[$loungeId])) {
            return $this->postsCache[$loungeId];
        }

        $base = rtrim((string) config('subculture-game-info.drivers.naver.base'), '/');
        $limit = (int) config('subculture-game-info.drivers.naver.feed_limit', self::FEED_LIMIT_FALLBACK);
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

                // 공지/뉴스 게시판은 쿠폰 관련 제목만(쿠폰 전용 게시판은 전부 본다).
                if (! $isCouponBoard && ! $this->titleLooksCoupon($title)) {
                    continue;
                }

                $posts[] = [
                    'boardId' => $boardId,
                    'title' => $title,
                    'body' => $this->extractDocumentText((string) ($feed['contents'] ?? '')),
                    'date' => (string) ($feed['createdDate'] ?? ''),
                ];
            }
        }

        return $this->postsCache[$loungeId] = $posts;
    }

    /**
     * 게시판 목록에서 (boardId, 쿠폰전용여부) 쌍을 고른다.
     * '쿠폰'이 든 게시판(단, 'IOS 쿠폰 입력' 같은 입력안내 게시판은 제외)은 쿠폰전용,
     * '공지'/'뉴스'가 든 게시판은 보조(제목 필터 적용). 쿠폰전용을 앞에 둬 코드를 우선 확보한다.
     *
     * @return array<int, array{0:int, 1:bool}>
     */
    private function pickBoards(string $base, string $loungeId): array
    {
        $json = $this->getJson("{$base}/lounge/{$loungeId}/board");
        if (! is_array($json)) {
            return [];
        }

        $couponBoards = [];
        $noticeBoards = [];
        $this->eachBoard($json['content'] ?? $json, function (int $id, string $name) use (&$couponBoards, &$noticeBoards) {
            $isCoupon = (mb_strpos($name, '쿠폰') !== false || mb_strpos($name, '코드') !== false)
                && mb_strpos($name, '입력') === false
                && mb_stripos($name, 'ios') === false;
            $isNotice = mb_strpos($name, '공지') !== false || mb_strpos($name, '뉴스') !== false;

            if ($isCoupon) {
                $couponBoards[$id] = true;
            } elseif ($isNotice) {
                $noticeBoards[$id] = true;
            }
        });

        $result = [];
        foreach (array_keys($couponBoards) as $id) {
            $result[] = [$id, true];
        }
        foreach (array_keys($noticeBoards) as $id) {
            if (! isset($couponBoards[$id])) {
                $result[] = [$id, false];
            }
        }

        return $result;
    }

    /** board 목록 JSON(중첩 구조 가능)을 재귀 순회하며 (boardId, boardName) 을 콜백으로 넘긴다. */
    private function eachBoard(mixed $node, callable $callback): void
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['boardId']) && is_numeric($node['boardId'])) {
            $name = (string) ($node['boardName'] ?? $node['name'] ?? '');
            if ($name !== '') {
                $callback((int) $node['boardId'], $name);
            }
        }
        foreach ($node as $child) {
            $this->eachBoard($child, $callback);
        }
    }

    private function titleLooksCoupon(string $title): bool
    {
        foreach (self::COUPON_TITLE_KEYWORDS as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 네이버 에디터 document JSON 을 평문 텍스트로 편다(코드/보상/기한이 본문 텍스트 안에 있다).
     * JSON 이 아니면(평문/HTML) 태그만 제거해 반환한다.
     */
    private function extractDocumentText(string $contents): string
    {
        $contents = trim($contents);
        if ($contents === '') {
            return '';
        }
        if ($contents[0] !== '{' && $contents[0] !== '[') {
            return $this->stripToText($contents);
        }
        $doc = json_decode($contents, true);
        if (! is_array($doc)) {
            return $this->stripToText($contents);
        }

        // document 트리를 훑어 'text'/'value'/'caption' 값(실제 보이는 글자)을 모은다.
        $texts = [];
        $collectText = function ($node) use (&$collectText, &$texts) {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_string($value) && in_array($key, ['text', 'value', 'caption'], true)) {
                    $texts[] = $value;
                } else {
                    $collectText($value);
                }
            }
        };
        $collectText($doc);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $texts)));
    }

    /** '쿠폰 코드 …' 마커 뒤의 첫 코드형 토큰(대소문자 보존). 코드가 아니면(노이즈/숫자) null. */
    private function extractCouponCode(string $text): ?string
    {
        $pattern = '/(?:'.self::CODE_MARKER.')\s*(?:\(\s*대소문자[^)]*\))?\s*[◈*:\-\s]*([A-Za-z0-9]{4,20})/u';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $code = $m[1];
        if (in_array(strtoupper($code), static::CODE_DENYLIST, true)) {
            return null;  // 보상명/페이지 단어 등
        }
        if (preg_match('/^\d+$/', $code)) {
            return null;  // 순수 숫자(연도/날짜 등)는 코드가 아님
        }

        return $code;
    }

    /** '쿠폰 보상' 마커 뒤 텍스트를 보상 문자열로 정리한다(인트로 '보상을…' 오매칭 방지). */
    private function extractRewards(string $text): ?string
    {
        $pattern = '/(?:쿠폰\s*보상|보상\s*내역)\s*[◈*:\-\s]*(.{4,120}?)(?:🗓|사용\s*기한|쿠폰\s*입력|입력\s*방법|$)/u';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $rewards = (string) preg_replace('/[◈*\-]+/u', ' ', $m[1]);
        $rewards = trim((string) preg_replace('/\s+/u', ' ', $rewards));

        return $rewards !== '' ? mb_substr($rewards, 0, 120) : null;
    }

    /**
     * 한국식 '~ M월 D일' 사용기한을 만료일시로 파싱한다.
     * 연도는 글 작성일(createdDate: yyyyMMdd…)에서 추론하고, 만료월이 작성월보다 많이 빠르면
     * 연말→연초로 넘어간 것으로 보고 +1년 한다. 못 찾으면 null(=기한 없음).
     */
    private function parseKoreanExpiry(string $title, string $body, string $createdDate): ?Carbon
    {
        // 1순위: 제목의 '~M월 D일'(종료일). 2순위: 본문에서 마지막으로 등장하는 'M월 D일'.
        if (preg_match('/~\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $title, $m)) {
            // 제목에서 종료일을 찾음 → $m 사용
        } elseif (preg_match_all('/(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $body, $all, PREG_SET_ORDER)) {
            $m = end($all);
        } else {
            return null;
        }

        $month = (int) $m[1];
        $day = (int) $m[2];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $year = strlen($createdDate) >= 4 ? (int) substr($createdDate, 0, 4) : (int) now()->year;
        $createdMonth = strlen($createdDate) >= 6 ? (int) substr($createdDate, 4, 2) : 0;
        if ($createdMonth > 0 && $month < $createdMonth - 1) {
            $year++;
        }

        try {
            return Carbon::create($year, $month, $day, 23, 59, 59, config('app.timezone', 'Asia/Seoul'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** 라운지 작성일(yyyyMMddHHmmss) → Carbon. 형식이 안 맞으면 null. */
    private function parseCreatedDate(string $createdDate): ?Carbon
    {
        if (! preg_match('/^\d{8}/', $createdDate)) {
            return null;
        }
        try {
            $padded = str_pad(substr($createdDate, 0, 14), 14, '0');

            return Carbon::createFromFormat('YmdHis', $padded, config('app.timezone', 'Asia/Seoul'));
        } catch (\Throwable) {
            return null;
        }
    }
}
