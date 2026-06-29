<?php

namespace App\Enums\SubcultureGameInfo;

enum CodeStatus: string
{
    case Unverified = 'unverified';
    case Active = 'active';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => '미검증',
            self::Active => '사용 가능',
            self::Expired => '만료',
        };
    }
}
