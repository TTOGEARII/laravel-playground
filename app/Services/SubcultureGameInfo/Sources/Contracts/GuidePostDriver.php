<?php

namespace App\Services\SubcultureGameInfo\Sources\Contracts;

use App\Enums\SubcultureGameInfo\GuideSource;

/**
 * 커뮤니티 공략글(개념글/추천글) 목록 수집 드라이버.
 * 본문은 가져오지 않고 목록 메타(제목/링크/작성일/조회수)만 파싱한다.
 */
interface GuidePostDriver
{
    public function source(): GuideSource;

    /**
     * @return \App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData[]
     */
    public function fetchPosts(string $gameSlug): array;
}
