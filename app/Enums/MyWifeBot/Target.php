<?php

namespace App\Enums\MyWifeBot;

/**
 * 캐릭터 타겟. 검증·폼 옵션의 단일 출처(매직 문자열 drift 방지).
 */
enum Target: string
{
    case All = 'all';
    case Male = 'male';
    case Female = 'female';
    case Teen = 'teen';

    public function label(): string
    {
        return match ($this) {
            self::All => '전체',
            self::Male => '남성',
            self::Female => '여성',
            self::Teen => '10대',
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
            ->mapWithKeys(fn (self $t) => [$t->value => $t->label()])
            ->all();
    }
}
