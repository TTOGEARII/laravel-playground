<?php

namespace App\Services\SubcultureGameInfo\Sources\DTO;

use Carbon\CarbonInterface;

/**
 * 커뮤니티(디씨/아카) 코드 검색 결과 1건.
 * - found: 검색 글 제목에서 해당 코드를 봤는지(교차검증 신호)
 * - recentAt: 그 코드가 보인 글 중 가장 최근 작성일(신선도 판단용)
 * - expiredHint: 같은 글 제목에 만료/종료 단서가 함께 있었는지
 */
final class CommunitySearchHit
{
    public function __construct(
        public bool $found,
        public string $source,        // 'dc-search' | 'arca-search'
        public ?string $url = null,
        public ?CarbonInterface $recentAt = null,
        public bool $expiredHint = false,
    ) {}
}
