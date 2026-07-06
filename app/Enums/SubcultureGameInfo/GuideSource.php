<?php

namespace App\Enums\SubcultureGameInfo;

/**
 * 공략글 출처(subculture_guide_posts.source).
 */
enum GuideSource: string
{
    case Dc = 'dc';
    case Arca = 'arca';

    public function label(): string
    {
        return match ($this) {
            self::Dc => '디시 개념글',
            self::Arca => '아카 추천글',
        };
    }
}
