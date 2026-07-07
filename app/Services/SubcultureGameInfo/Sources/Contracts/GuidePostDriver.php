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

    /**
     * 제목 검색으로 공략글을 수집한다(예: "비나 공략").
     * 개념글/추천글 목록만으로는 레이드 공략 노출이 적어, 보스명 검색으로 보강하는 용도.
     *
     * @return \App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData[]
     */
    public function searchPosts(string $gameSlug, string $keyword): array;
}
