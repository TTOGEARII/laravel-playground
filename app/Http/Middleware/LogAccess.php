<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 외부 유저 접속 로그 — 페이지 조회(웹 GET·HTML)만 남긴다.
 * terminate() 에서 기록하므로 응답이 나간 뒤 실행돼 요청 지연이 없다.
 * 자산/XHR/API/헬스체크·봇은 제외해 실제 방문만 담는다. 실패해도 서비스에 영향 없음(로그만).
 */
class LogAccess
{
    /** device 판정용 UA 패턴(순서대로 검사). */
    private const BOT = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|curl|wget|python-requests|axios|headless|lighthouse|pingdom|uptime/i';

    private const TABLET = '/ipad|tablet|playbook|silk|(android(?!.*mobile))/i';

    private const MOBILE = '/mobile|iphone|ipod|android|blackberry|iemobile|opera mini|windows phone/i';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** 응답 전송 후 기록(요청 지연 없음). */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldLog($request)) {
                return;
            }

            AccessLog::create([
                'ip' => $request->ip(),
                'device' => $this->device((string) $request->userAgent()),
                'method' => $request->getMethod(),
                'path' => mb_substr($request->getRequestUri(), 0, 512),
                'referrer' => $this->clean($request->headers->get('referer')),
                'user_agent' => $this->clean($request->userAgent()),
                'user_id' => $request->user()?->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AccessLog] 기록 실패', ['error' => $e->getMessage()]);
        }
    }

    /** 페이지 조회(브라우저 GET·HTML)만 대상. 자산/XHR/API/헬스체크 제외. */
    private function shouldLog(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson() || ! $request->acceptsHtml()) {
            return false;
        }

        // 헬스체크·잡파일 경로 제외
        $path = $request->path();

        return ! preg_match('#^(up|build/|favicon\.ico|robots\.txt|sw\.js|manifest\.|\.well-known/)#', $path);
    }

    private function device(string $ua): string
    {
        return match (true) {
            $ua === '' => 'pc',
            (bool) preg_match(self::BOT, $ua) => 'bot',
            (bool) preg_match(self::TABLET, $ua) => 'tablet',
            (bool) preg_match(self::MOBILE, $ua) => 'mobile',
            default => 'pc',
        };
    }

    private function clean(?string $v): ?string
    {
        return $v !== null && $v !== '' ? mb_substr($v, 0, 512) : null;
    }
}
