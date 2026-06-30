<?php

namespace App\Enums;

/** 문의 유형. */
enum InquiryCategory: string
{
    case General = 'general';
    case Bug = 'bug';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::General => '일반 문의',
            self::Bug => '버그 제보',
            self::Feature => '기능 요청',
        };
    }
}
