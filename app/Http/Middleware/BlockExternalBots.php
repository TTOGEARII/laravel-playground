<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * 악성/자동 요청을 403 으로 막는다. 세 겹으로 판정한다(먼저 걸리는 순서):
 *
 *  1) 공격 시그니처 — URL(경로+쿼리)에 LFI·경로탐색·XSS·SQLi·취약경로 스캔 등
 *     "정상 브라우저가 절대 만들지 않는" 패턴이 있으면 UA·IP 무관 차단.
 *     UA 를 브라우저로 위장한 스캐너(예: .env·wp-config 탈취 시도)도 여기서 잡힌다.
 *  2) 차단 IP 목록 — blocked_ips 테이블(캐시)의 IP 는 UA 무관 차단.
 *     접속 로그 분석으로 찾은 공격 IP 를 ip:block 커맨드로 등록한다.
 *  3) 봇 UA — 내부(루프백·사설 대역) IP 는 항상 통과(배포 헬스체크·모니터링 보호),
 *     정식 검색엔진·링크 미리보기(config allow)는 통과, 스크래퍼·크롤러(config block)는 차단.
 *
 * 정책·시그니처·봇 목록은 config/security.php 단일 출처.
 */
class BlockExternalBots
{
    /** 내부로 간주해 항상 통과시키는 대역(루프백·사설·IPv6 로컬). */
    private const INTERNAL_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        // 1) 공격 시그니처 — UA·IP 무관 즉시 차단(정상 요청엔 없는 패턴).
        if ($this->hasAttackSignature($request)) {
            Log::warning('[Security] 공격 시그니처 차단', ['ip' => $ip, 'uri' => $request->getRequestUri()]);

            return $this->deny();
        }

        // 2) 차단 IP 목록(DB·캐시) — UA 무관 차단.
        if ($ip !== null && $this->isBlockedIp($ip)) {
            return $this->deny();
        }

        // 3) 내부 요청은 무조건 통과(헬스체크·도커 내부 트래픽 보호).
        if ($ip === null || IpUtils::checkIp($ip, self::INTERNAL_RANGES)) {
            return $next($request);
        }

        $ua = mb_strtolower((string) $request->userAgent());

        // 공인 IP + 빈 UA = 정상 브라우저가 아님(UA 를 지운 스크립트) → 차단.
        if ($ua === '') {
            return $this->deny();
        }

        // 검색엔진·링크 미리보기는 봇이어도 허용.
        foreach ((array) config('security.bots.allow', []) as $needle) {
            if (str_contains($ua, $needle)) {
                return $next($request);
            }
        }

        // 스크래퍼·자동 클라이언트·AI 크롤러는 차단.
        foreach ((array) config('security.bots.block', []) as $needle) {
            if (str_contains($ua, $needle)) {
                return $this->deny();
            }
        }

        return $next($request);
    }

    /** URL(경로+쿼리)에 공격 시그니처가 있는지 — 원본·디코드(이중 포함) 문자열을 함께 검사. */
    private function hasAttackSignature(Request $request): bool
    {
        $signatures = (array) config('security.attack_signatures', []);
        if ($signatures === []) {
            return false;
        }

        $uri = $request->getRequestUri();
        // 인코딩 회피 방지 — 원본 + 1회/2회 디코드본을 모두 소문자로 검사한다.
        $once = rawurldecode($uri);
        $haystacks = [
            mb_strtolower($uri),
            mb_strtolower($once),
            mb_strtolower(rawurldecode($once)),
        ];

        foreach ($signatures as $sig) {
            $needle = mb_strtolower((string) $sig);
            foreach ($haystacks as $h) {
                if (str_contains($h, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 차단 IP 목록(blocked_ips)에 있는지 — 매 요청 DB 조회를 피해 짧게 캐시. */
    private function isBlockedIp(string $ip): bool
    {
        try {
            $blocked = Cache::remember(
                BlockedIp::CACHE_KEY,
                now()->addMinutes(5),
                fn () => BlockedIp::pluck('ip')->all()
            );

            return in_array($ip, $blocked, true);
        } catch (\Throwable $e) {
            // 블록리스트 조회 실패(테이블 없음·DB 순단 등)로 사이트 전체를 죽이지 않는다.
            // 시그니처 차단은 DB 와 무관하게 계속 동작하므로 여기선 통과(가용성 우선).
            return false;
        }
    }

    private function deny(): Response
    {
        // 정중히 거절 + 색인 금지 힌트. 본문은 최소화.
        return response('Forbidden', Response::HTTP_FORBIDDEN, [
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
