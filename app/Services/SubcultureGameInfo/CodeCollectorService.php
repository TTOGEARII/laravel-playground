<?php

namespace App\Services\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\Sources\SourceRunner;

/**
 * 수집 오케스트레이터: 게임 카탈로그 보장 → 모든 소스(드라이버) 수집 →
 * 교차검증 동기화 → 만료 경과분 정리. 개별 드라이버 실패는 SourceRunner 가 격리한다.
 */
class CodeCollectorService
{
    public function __construct(
        private CodeSyncService $sync,
        private SourceRunner $runner,
    ) {}

    /**
     * @return array{created:int, updated:int, skipped:int, collected:int, expired:int}
     */
    public function collect(bool $includeCommunity = true): array
    {
        $this->sync->ensureGames();

        $dtos = $this->runner->run($includeCommunity);
        $stats = $this->sync->sync($dtos);
        $stats['collected'] = count($dtos);
        $stats['expired'] = $this->sync->markExpiredPastDue();

        return $stats;
    }
}
