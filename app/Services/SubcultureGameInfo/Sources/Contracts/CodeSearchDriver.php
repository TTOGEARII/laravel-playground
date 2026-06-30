<?php

namespace App\Services\SubcultureGameInfo\Sources\Contracts;

use App\Services\SubcultureGameInfo\Sources\DTO\CommunitySearchHit;

/**
 * 특정 코드를 커뮤니티에서 '검색'해 한 번 더 검증하는 드라이버(디씨/아카).
 * collect(목록 스캔)와 별개로, 이미 수집한 코드의 신선도/유효성을 재확인한다.
 */
interface CodeSearchDriver
{
    /**
     * 해당 게임 커뮤니티에서 코드를 검색한다. 검색 불가(매핑 없음)·요청 실패면 null.
     */
    public function searchCode(string $gameSlug, string $code): ?CommunitySearchHit;
}
