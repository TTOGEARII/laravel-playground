<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 전역 보안 응답 헤더를 부착한다.
 *
 * - X-Content-Type-Options: nosniff — 브라우저의 MIME 스니핑 차단.
 *   Laravel 이 내려주는 동적 응답(JSON/HTML)이 다른 타입으로 해석돼 실행되는 것을 막는다.
 *   (참고: /public 의 업로드 이미지는 웹서버가 정적 서빙해 이 미들웨어를 거치지 않으므로,
 *    그쪽 위장 파일 방어는 saveImage 의 확장자 화이트리스트 + 웹서버 설정이 담당한다.)
 * - X-Frame-Options: SAMEORIGIN — 외부 사이트 iframe 삽입을 막아 클릭재킹 방지.
 * - Referrer-Policy — 외부로 전체 URL(쿼리 포함)이 새어 나가지 않도록 제한.
 * - X-Permitted-Cross-Domain-Policies: none — Flash/PDF 등의 크로스도메인 정책 남용 차단.
 *
 * CSP(Content-Security-Policy)는 Vite HMR·Phaser CDN·Google Fonts·인라인 스크립트를 함께 고려한
 * 별도 튜닝이 필요해 여기서는 넣지 않는다(잘못된 CSP 는 없느니만 못하다). 도입 시 nonce 기반으로 확장할 것.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        return $response;
    }
}
