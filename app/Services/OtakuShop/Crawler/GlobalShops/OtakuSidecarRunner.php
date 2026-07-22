<?php

namespace App\Services\OtakuShop\Crawler\GlobalShops;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * 오타쿠샵 해외관용 Playwright 사이드카(tools/raid-crawler/otaku-*.mjs) 실행기.
 *
 * SubcultureGameInfo CrawlerScriptRunner 와 같은 패턴 — Node/Playwright 설치를
 * 그쪽 config(subculture-game-info.raids.crawler)와 공유해 브라우저·node_modules
 * 중복 설치를 만들지 않는다. Sail 이미지가 PLAYWRIGHT_BROWSERS_PATH=0 을 컨테이너
 * env 로 심어두므로(.env 로 못 덮음) browsers_path 를 프로세스에 직접 주입한다.
 *
 * stdout 의 JSON 계약({shop, source, items})을 파싱해 돌려주고,
 * 실패는 로그 후 null 폴백한다(사이드카 장애가 커맨드 전체를 죽이지 않도록).
 */
class OtakuSidecarRunner
{
    /**
     * @param  array<int, string>  $args  스크립트에 넘길 --key=value 인자들
     * @return array{shop?: string, source?: string, items: array<int, array<string, mixed>>}|null
     */
    public function run(string $script, array $args, int $timeoutSec): ?array
    {
        // Playwright 브라우저/Node 는 SGI 사이드카와 단일 설치를 공유한다(단일 출처).
        $sidecar = (array) config('subculture-game-info.raids.crawler', []);
        $env = array_filter([
            'PLAYWRIGHT_BROWSERS_PATH' => $sidecar['browsers_path'] ?? null,
            'SGI_PLAYWRIGHT_WS' => $sidecar['playwright_ws'] ?? null,
            'SGI_CRAWLER_UA' => config('subculture-game-info.http.user_agent'),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $result = Process::timeout($timeoutSec)
                ->env($env)
                ->run([$sidecar['node_binary'] ?? 'node', $script, ...$args]);
        } catch (\Throwable $e) {
            Log::warning('[오타쿠샵-해외관] 사이드카 프로세스 예외', ['script' => $script, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            Log::warning('[오타쿠샵-해외관] 사이드카 실행 실패', [
                'script' => $script,
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 1000),
            ]);

            return null;
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded) || ! is_array($decoded['items'] ?? null)) {
            Log::warning('[오타쿠샵-해외관] 사이드카 출력 JSON 계약 불일치', ['script' => $script]);

            return null;
        }

        return $decoded;
    }
}
