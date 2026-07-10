<?php

namespace App\Services\SubcultureGameInfo\Raids;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Playwright 사이드카(tools/raid-crawler) 실행기.
 * stdout 의 JSON 계약({game, type, source, items})을 파싱해 돌려주고,
 * 실패는 로그 후 null 폴백한다(크롤 실패가 배치 전체를 죽이지 않도록).
 */
class CrawlerScriptRunner
{
    /**
     * @param  'characters'|'raids'|'attribute-parties'  $type
     * @return array{source: string, items: array<int, array>}|null
     */
    public function run(string $gameSlug, string $type): ?array
    {
        $cfg = config('subculture-game-info.raids.crawler');
        $sourceCfg = $cfg['sources'][$gameSlug] ?? null;
        if ($sourceCfg === null) {
            Log::warning('[SGI-RAID] 크롤 소스 미정의', ['game' => $gameSlug]);

            return null;
        }

        $env = array_filter([
            'PLAYWRIGHT_BROWSERS_PATH' => $cfg['browsers_path'] ?? null,
            'SGI_PLAYWRIGHT_WS' => $cfg['playwright_ws'] ?? null,
            'SGI_CRAWLER_UA' => config('subculture-game-info.http.user_agent'),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $result = Process::timeout((int) $cfg['timeout'])
                ->env($env)
                ->run([
                    $cfg['node_binary'],
                    $cfg['script'],
                    "--game={$gameSlug}",
                    "--type={$type}",
                    "--base={$sourceCfg['base']}",
                ]);
        } catch (\Throwable $e) {
            Log::warning('[SGI-RAID] 크롤 프로세스 예외', ['game' => $gameSlug, 'type' => $type, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            Log::warning('[SGI-RAID] 크롤 스크립트 실패', [
                'game' => $gameSlug,
                'type' => $type,
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 1000),
            ]);

            return null;
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded) || ! is_array($decoded['items'] ?? null)) {
            Log::warning('[SGI-RAID] 크롤 출력 JSON 계약 불일치', ['game' => $gameSlug, 'type' => $type]);

            return null;
        }

        return [
            'source' => (string) ($decoded['source'] ?? ($sourceCfg['source'] ?? 'unknown')),
            'items' => $decoded['items'],
        ];
    }

    /**
     * 실브라우저로 페이지 HTML 을 가져온다(Cloudflare 등으로 일반 HTTP 가 막히는 페이지용).
     *
     * @param  string|null  $waitSelector  이 셀렉터가 나타날 때까지 대기(챌린지 통과 신호)
     */
    public function fetchHtml(string $url, ?string $waitSelector = null): ?string
    {
        $cfg = config('subculture-game-info.raids.crawler');

        $env = array_filter([
            'PLAYWRIGHT_BROWSERS_PATH' => $cfg['browsers_path'] ?? null,
            'SGI_PLAYWRIGHT_WS' => $cfg['playwright_ws'] ?? null,
            'SGI_CRAWLER_UA' => config('subculture-game-info.http.user_agent'),
        ], fn ($v) => $v !== null && $v !== '');

        $args = [
            $cfg['node_binary'],
            dirname((string) $cfg['script']).'/fetch-html.mjs',
            "--url={$url}",
        ];
        if ($waitSelector !== null) {
            $args[] = "--wait={$waitSelector}";
        }

        try {
            $result = Process::timeout((int) $cfg['timeout'])->env($env)->run($args);
        } catch (\Throwable $e) {
            Log::warning('[SGI-RAID] fetch-html 프로세스 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            Log::warning('[SGI-RAID] fetch-html 실패', [
                'url' => $url,
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);

            return null;
        }

        $decoded = json_decode($result->output(), true);
        $html = is_array($decoded) ? ($decoded['html'] ?? null) : null;

        return is_string($html) && $html !== '' ? $html : null;
    }
}
