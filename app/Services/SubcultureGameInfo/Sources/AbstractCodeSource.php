<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Services\SubcultureGameInfo\Sources\Contracts\CodeSourceInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 코드 소스 공통 베이스: 실제 브라우저 UA로 HTTP 요청을 보내고,
 * 외부 사이트 실패는 로그 후 폴백(null/빈배열)하는 방어적 처리를 제공한다.
 */
abstract class AbstractCodeSource implements CodeSourceInterface
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

    /** 단일 토큰이 코드처럼 보이는가: 영숫자 4~30, 대문자 위주, 길이≥6 또는 숫자포함, 비-denylist. */
    protected function looksLikeCode(string $token): bool
    {
        if (! preg_match('/^[A-Za-z0-9]{4,30}$/', $token)) {
            return false;
        }
        $upper = preg_match_all('/[A-Z]/', $token);
        $alpha = preg_match_all('/[A-Za-z]/', $token);
        if ($alpha > 0 && $upper / max($alpha, 1) < 0.6) {
            return false; // 소문자 위주 산문 토큰 배제
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

    protected function http(): PendingRequest
    {
        $cfg = config('subculture-game-info.http');

        return Http::withHeaders([
            'User-Agent' => $cfg['user_agent'],
            'Accept-Language' => 'ko-KR,ko;q=0.9,en;q=0.8',
            // 실제 브라우저처럼 보이게 하는 헤더 — 일부 사이트의 짧은/차단 응답 회피
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Upgrade-Insecure-Requests' => '1',
        ])
            ->timeout($cfg['timeout'] ?? 15)
            ->retry($cfg['retry'] ?? 2, 1500, throw: false);
    }

    /** HTML 문자열을 가져온다. 실패 시 null. */
    protected function getHtml(string $url, array $query = []): ?string
    {
        try {
            $req = $this->http()
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
            // 빈 query 를 넘기면 Guzzle 이 URL의 기존 쿼리스트링을 덮어써 버린다 → 비어있으면 생략
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

    /** JSON 응답을 배열로 가져온다. 실패 시 null. */
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

    /** HTML 태그 제거 + 공백 정규화 (텍스트 추출용). */
    protected function stripToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
