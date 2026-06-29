<?php

namespace App\Enums\SubcultureGameInfo;

enum CodeRegion: string
{
    case Global = 'global';
    case Asia = 'asia';
    case Kr = 'kr';
    case Jp = 'jp';
    case Cn = 'cn';

    public function label(): string
    {
        return match ($this) {
            self::Global => '글로벌',
            self::Asia => '아시아',
            self::Kr => '한국',
            self::Jp => '일본',
            self::Cn => '중국',
        };
    }
}
