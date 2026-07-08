<?php

namespace App\Services\SubcultureGameInfo\Sources\DTO;

use Carbon\Carbon;

/**
 * 커뮤니티 공략글 1건의 목록 메타(본문 없음).
 */
final readonly class GuidePostData
{
    public function __construct(
        public string $externalId,
        public string $title,
        public string $url,
        public ?Carbon $postedAt = null,
        public int $views = 0,
        public int $rate = 0, // 추천 수 — 수집 상한 안에서 인기 글 우선 선별에 사용
    ) {}
}
