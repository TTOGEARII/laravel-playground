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
}
