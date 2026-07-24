<?php

namespace App\Services\EventCalendar\Sources;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * 행사 캘린더용 Playwright 사이드카(tools/raid-crawler/event-*.mjs) 실행기.
 * OtakuSidecarRunner 와 같은 패턴 — Node/Playwright 설치는 SGI config
 * (subculture-game-info.raids.crawler)와 공유(browsers_path 직접 주입, 중복 설치 없음).
 * 실패는 로그 후 null 폴백(사이드카 장애가 수집 전체를 죽이지 않게).
 */
class EventSidecarRunner
{
    /**
     * @return array{source?: string, items: array<int, array<string, mixed>>}|null
     */
    public function run(string $script, int $timeoutSec = 120): ?array
    {
        $sidecar = (array) config('subculture-game-info.raids.crawler', []);
        $env = array_filter([
            'PLAYWRIGHT_BROWSERS_PATH' => $sidecar['browsers_path'] ?? null,
            'SGI_CRAWLER_UA' => config('event-calendar.user_agent'),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $result = Process::timeout($timeoutSec)
                ->env($env)
                ->run([$sidecar['node_binary'] ?? 'node', $script]);
        } catch (\Throwable $e) {
            Log::warning('[행사캘린더] 사이드카 프로세스 예외', ['script' => $script, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            Log::warning('[행사캘린더] 사이드카 실행 실패', [
                'script' => $script,
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);

            return null;
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded) || ! is_array($decoded['items'] ?? null)) {
            Log::warning('[행사캘린더] 사이드카 출력 JSON 계약 불일치', ['script' => $script]);

            return null;
        }

        return $decoded;
    }
}
