<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * 외부(공인 IP)에서 오는 봇 요청을 403 으로 막는다.
 *
 * - 내부(루프백·사설 대역) IP 는 항상 통과 → 배포 헬스체크(도커 게이트웨이 172.x)·
 *   모니터링·서버 내부 요청을 절대 막지 않는다("외부에서 들어오는 봇만" 차단).
 * - 정식 검색엔진·링크 미리보기(config security.bots.allow)는 통과 → SEO·공유 카드 유지.
 * - 스크래퍼·자동 HTTP 클라이언트·AI 크롤러(config security.bots.block)는 차단.
 * - 정상 브라우저는 봇 UA 가 아니라 영향 없음.
 *
 * 판정은 UA 문자열만 본다(역DNS 검증 없음) — 개인 사이트 규모에서 캐주얼 스크래퍼를
 * 걸러내는 게 목적이고, UA 위장까지 막는 건 과하다. 정책은 config 단일 출처.
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

        // 내부 요청은 무조건 통과(헬스체크·도커 내부 트래픽 보호).
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

    private function deny(): Response
    {
        // 정중히 거절 + 색인 금지 힌트. 본문은 최소화.
        return response('Forbidden', Response::HTTP_FORBIDDEN, [
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
