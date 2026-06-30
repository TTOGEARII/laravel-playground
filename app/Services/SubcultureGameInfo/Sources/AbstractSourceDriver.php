<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Services\SubcultureGameInfo\Sources\Contracts\SourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CommunitySearchHit;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 수집 드라이버 공통 베이스. 실제 브라우저 UA로 HTTP 요청, 방어적 폴백,
 * 코드 토큰 판별, 만료일 파싱 등 드라이버들이 공유하는 헬퍼를 제공한다.
 */
abstract class AbstractSourceDriver implements SourceDriver
{
    /** 코드로 보기 어려운 대문자 토큰 제외 목록(보상명/페이지 chrome 등). */
    protected const CODE_DENYLIST = [
        'HTTP', 'HTTPS', 'HTML', 'HTML5', 'JSON', 'CSS', 'URL', 'API', 'UID', 'CDN', 'FAQ',
        'NEW', 'ALL', 'AND', 'THE', 'FOR', 'YOU', 'GET', 'NOW', 'HOW', 'USE', 'ADD',
        'CODE', 'CODES', 'COUPON', 'COUPONS', 'REDEEM', 'GIFT', 'REWARD', 'REWARDS',
        'ACTIVE', 'EXPIRED', 'INACTIVE', 'VALID', 'INVALID', 'STATUS', 'LIST', 'GUIDE',
        'GLOBAL', 'ASIA', 'JAPAN', 'KOREA', 'SERVER', 'REGION', 'IOS', 'APP', 'GAME', 'GAMES',
        'NEXON', 'YOSTAR', 'KURO', 'HOYOVERSE', 'EPID', 'BILIBILI',
        'ASTRITE', 'PYROXENE', 'POLYCHROME', 'PRIMOGEM', 'PRIMOGEMS', 'STELLAR', 'JADE',
        'CREDIT', 'CREDITS', 'SHELL', 'EXP', 'NOTOK',
    ];

    public function isCommunity(): bool
    {
        return false;
    }

    // ---------------------------------------------------------------- HTTP
    protected function http(): PendingRequest
    {
        $cfg = config('subculture-game-info.http');

        return Http::withHeaders([
            'User-Agent' => $cfg['user_agent'],
            'Accept-Language' => 'ko-KR,ko;q=0.9,en;q=0.8',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Upgrade-Insecure-Requests' => '1',
        ])
            ->timeout($cfg['timeout'] ?? 15)
            ->retry($cfg['retry'] ?? 2, 1500, throw: false);
    }

    protected function getHtml(string $url, array $query = []): ?string
    {
        try {
            $req = $this->http()->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
            // 빈 query 를 넘기면 Guzzle 이 URL의 기존 쿼리스트링을 덮어쓰므로 비어있으면 생략
            $res = $query === [] ? $req->get($url) : $req->get($url, $query);
            if (! $res->successful()) {
                Log::warning('[SGI] HTML fetch 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            return $res->body();
        } catch (\Throwable $e) {
            Log::warning('[SGI] HTML fetch 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function getJson(string $url, array $query = []): ?array
    {
        try {
            $req = $this->http()->withHeaders(['Accept' => 'application/json']);
            $res = $query === [] ? $req->get($url) : $req->get($url, $query);
            if (! $res->successful()) {
                Log::warning('[SGI] JSON fetch 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::warning('[SGI] JSON fetch 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ---------------------------------------------------------------- 파싱 헬퍼
    /** DOMXPath 생성(UTF-8). */
    protected function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    protected function stripToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** 단일 토큰이 코드처럼 보이는가. */
    protected function looksLikeCode(string $token): bool
    {
        if (! preg_match('/^[A-Za-z0-9]{4,30}$/', $token)) {
            return false;
        }
        // 순수 숫자(연도 2025·글번호 173188 등)는 코드가 아니다 — 실제 리딤코드는 거의 항상 영문을 포함.
        if (! preg_match('/[A-Za-z]/', $token)) {
            return false;
        }
        $upper = preg_match_all('/[A-Z]/', $token);
        $alpha = preg_match_all('/[A-Za-z]/', $token);
        if ($alpha > 0 && $upper / max($alpha, 1) < 0.6) {
            return false;
        }
        $hasDigit = (bool) preg_match('/[0-9]/', $token);
        if (strlen($token) < 6 && ! $hasDigit) {
            return false;
        }

        return ! in_array(strtoupper($token), static::CODE_DENYLIST, true);
    }

    /** 자유 텍스트에서 코드형 토큰 추출(중복 제거, 원문 케이스 보존). */
    protected function extractCodeTokensFromText(string $text): array
    {
        $out = [];
        if (preg_match_all('/[A-Za-z0-9]{4,30}/', $text, $m)) {
            foreach ($m[0] as $t) {
                if ($this->looksLikeCode($t)) {
                    $out[strtoupper($t)] = $t;
                }
            }
        }

        return array_values($out);
    }

    /** 텍스트에서 만료 일시를 파싱(KST). 못 찾으면 null. */
    protected function parseExpiry(?string $text): ?Carbon
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Seoul');
        $patterns = [
            '/\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}(?:[ T]\d{1,2}:\d{2})?/',          // 2026-06-30 10:59
            '/[A-Z][a-z]+\.?\s+\d{1,2},?\s+\d{4}/',                              // June 28, 2026
            '/\d{1,2}\s+[A-Z][a-z]+\.?\s+\d{4}/',                                // 28 June 2026
            '/\d{1,2}\/\d{1,2}\/\d{4}/',                                         // 06/28/2026
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m)) {
                // 점 구분(2026.06.30)은 Carbon 이 직접 파싱 못 하므로 날짜부의 점을 하이픈으로 정규화.
                $candidate = preg_replace('/^(\d{4})\.(\d{1,2})\.(\d{1,2})/', '$1-$2-$3', $m[0]) ?? $m[0];
                try {
                    return Carbon::parse($candidate, $tz);
                } catch (\Throwable) {
                    // 다음 패턴 시도
                }
            }
        }

        return null;
    }

    protected function regionFor(string $gameSlug): CodeRegion
    {
        $def = config("subculture-game-info.games.{$gameSlug}.region_default", 'global');

        return CodeRegion::tryFrom($def) ?? CodeRegion::Global;
    }

    /** URL 호스트로 소스 키 부여(mollulog, wuthering, honeybeejoa ...). */
    protected function hostLabel(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'web';
        $host = preg_replace('/^www\./', '', $host);

        return substr(explode('.', (string) $host)[0] ?? 'web', 0, 40);
    }

    /** 코드 보상을 문자열로 정규화(문자열/배열/{name,count} 모두 지원). */
    protected function normalizeRewards(mixed $rewards): ?string
    {
        if (is_string($rewards)) {
            return trim($rewards) !== '' ? trim($rewards) : null;
        }
        if (! is_array($rewards)) {
            return null;
        }
        $parts = [];
        foreach ($rewards as $r) {
            if (is_string($r)) {
                $parts[] = $r;
            } elseif (is_array($r)) {
                $name = $r['name'] ?? null;
                $count = $r['count'] ?? $r['amount'] ?? null;
                if ($name) {
                    $parts[] = $count ? "{$name} x{$count}" : $name;
                }
            }
        }
        $parts = array_filter(array_map('trim', $parts));

        return $parts !== [] ? implode(', ', array_slice($parts, 0, 6)) : null;
    }

    /** 만료 단서(검색 글 제목에 함께 보이면 그 코드를 만료로 본다). */
    protected const EXPIRED_SEARCH_HINTS = ['만료', '종료', '마감', '이미 사용', 'expired', 'ended'];

    /**
     * 검색 글 목록(제목+작성일)에서 코드 포함 여부·최근일·만료단서를 평가한다.
     * 디씨/아카 검색 드라이버가 마크업별로 (제목, 작성일) 쌍을 만들어 넘긴다.
     *
     * @param  array<int, array{0: string, 1: ?\Carbon\CarbonInterface}>  $rows
     */
    protected function evaluateSearchRows(array $rows, string $code, string $source, ?string $url): CommunitySearchHit
    {
        $found = false;
        $recentAt = null;
        $expiredHint = false;

        foreach ($rows as [$title, $date]) {
            if ($title === '' || mb_stripos($title, $code) === false) {
                continue;
            }
            $found = true;
            if ($date !== null && ($recentAt === null || $date->gt($recentAt))) {
                $recentAt = $date;
            }
            foreach (self::EXPIRED_SEARCH_HINTS as $hint) {
                if (mb_stripos($title, $hint) !== false) {
                    $expiredHint = true;
                    break;
                }
            }
        }

        return new CommunitySearchHit($found, $source, $url, $recentAt, $expiredHint);
    }

    /** 코드 공유 키워드가 든 링크(제목)에서만 코드 토큰 추출(커뮤니티 보조용). */
    protected function extractCodesFromLinkTitles(string $html): array
    {
        $keywords = ['리딤', '쿠폰', '코드', '교환', 'coupon', 'code', 'redeem'];
        $found = [];
        foreach ($this->xpath($html)->query('//a') ?: [] as $a) {
            $title = trim($a->textContent);
            if ($title === '') {
                continue;
            }
            $lower = mb_strtolower($title);
            $hit = false;
            foreach ($keywords as $kw) {
                if (mb_strpos($lower, mb_strtolower($kw)) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                continue;
            }
            foreach ($this->extractCodeTokensFromText($title) as $tok) {
                $found[strtoupper($tok)] = $tok;
            }
        }

        return array_values($found);
    }
}
