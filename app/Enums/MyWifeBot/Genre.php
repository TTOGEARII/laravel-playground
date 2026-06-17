<?php

namespace App\Enums\MyWifeBot;

/**
 * 캐릭터 장르. 검증·폼 옵션의 단일 출처(매직 문자열 drift 방지).
 */
enum Genre: string
{
    case Romance = 'romance';
    case Fantasy = 'fantasy';
    case Action = 'action';
    case SliceOfLife = 'slice_of_life';
    case Otaku = 'otaku';

    public function label(): string
    {
        return match ($this) {
            self::Romance => '로맨스',
            self::Fantasy => '판타지',
            self::Action => '액션',
            self::SliceOfLife => '일상',
            self::Otaku => '오타쿠/서브컬처',
        };
    }

    /**
     * 폼/뷰용 value => label 맵.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $g) => [$g->value => $g->label()])
            ->all();
    }
}
