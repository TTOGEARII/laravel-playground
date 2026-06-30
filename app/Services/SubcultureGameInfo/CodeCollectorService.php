<?php

namespace App\Services\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\Sources\SourceRunner;

/**
 * 수집 오케스트레이터: 게임 카탈로그 보장 → 모든 소스(드라이버) 수집 →
 * 교차검증 동기화 → 커뮤니티 검색 재검증 → 만료 경과분 정리.
 * 개별 드라이버 실패는 SourceRunner 가 격리한다.
 */
class CodeCollectorService
{
    public function __construct(
        private CodeSyncService $sync,
        private SourceRunner $runner,
        private CommunitySearchVerifier $verifier,
    ) {}

    /**
     * @return array{created:int, updated:int, skipped:int, collected:int, expired:int, corroborated:int, search_expired:int}
     */
    public function collect(bool $includeCommunity = true, bool $verify = true): array
    {
        $this->sync->ensureGames();

        $dtos = $this->runner->run($includeCommunity);
        $stats = $this->sync->sync($dtos);
        $stats['collected'] = count($dtos);

        // 커뮤니티 검색으로 미검증 코드를 한 번 더 검증(신선도↑ / 만료 단서 시 만료).
        $stats['corroborated'] = 0;
        $stats['search_expired'] = 0;
        if ($verify) {
            $v = $this->verifier->verify();
            $stats['corroborated'] = $v['corroborated'];
            $stats['search_expired'] = $v['expired'];
        }

        // 만료일이 지난 코드 일괄 정리(검색 만료 처리 반영 후).
        $stats['expired'] = $this->sync->markExpiredPastDue();

        return $stats;
    }
}
