<?php

namespace App\Services\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\Sources\AggregatorHtmlSource;
use App\Services\SubcultureGameInfo\Sources\Contracts\CodeSourceInterface;
use App\Services\SubcultureGameInfo\Sources\DcCommunitySource;
use App\Services\SubcultureGameInfo\Sources\HoyoverseApiSource;
use Illuminate\Support\Facades\Log;

/**
 * 수집 오케스트레이터: 게임 카탈로그를 보장하고, 권위 순서(메인 → 보조)로
 * 소스들을 실행해 수집물을 모아 동기화한다. 개별 소스 실패는 격리(로그+폴백).
 */
class CodeCollectorService
{
    public function __construct(
        private CodeSyncService $sync,
        private HoyoverseApiSource $hoyoverse,
        private AggregatorHtmlSource $aggregator,
        private DcCommunitySource $community,
    ) {}

    /**
     * @return array{created:int, updated:int, skipped:int, collected:int}
     */
    public function collect(bool $includeCommunity = true): array
    {
        $this->sync->ensureGames();

        // 메인(권위) 먼저, 보조(커뮤니티) 나중 — sync 의 권위 규칙과 맞춤
        $sources = [$this->hoyoverse, $this->aggregator];
        if ($includeCommunity) {
            $sources[] = $this->community;
        }

        $dtos = [];
        foreach ($sources as $source) {
            /** @var CodeSourceInterface $source */
            try {
                $dtos = array_merge($dtos, $source->fetch());
            } catch (\Throwable $e) {
                Log::error('[SGI] 소스 수집 실패', ['source' => $source->key(), 'error' => $e->getMessage()]);
            }
        }

        $stats = $this->sync->sync($dtos);
        $stats['collected'] = count($dtos);

        return $stats;
    }
}
