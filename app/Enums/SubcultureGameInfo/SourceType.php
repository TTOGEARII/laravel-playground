<?php

namespace App\Enums\SubcultureGameInfo;

enum SourceType: string
{
    case Aggregator = 'aggregator';   // 공식/정리 사이트(메인 신뢰 소스)
    case Community = 'community';      // 디씨/아카 등 커뮤니티(보조 신호)

    public function label(): string
    {
        return match ($this) {
            self::Aggregator => '정리 사이트',
            self::Community => '커뮤니티',
        };
    }
}
