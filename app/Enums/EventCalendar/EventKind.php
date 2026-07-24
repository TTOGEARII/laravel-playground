<?php

namespace App\Enums\EventCalendar;

/**
 * 행사 종류. 캘린더 필터 탭과 색상 구분의 기준.
 */
enum EventKind: string
{
    case Concert = 'concert'; // 내한공연(J-pop 등 — genre 로 세분)
    case Doujin = 'doujin';   // 동인 행사(코믹월드·일러스타페스)
    case Expo = 'expo';       // 기업/전시 행사(AGF 등)

    public function label(): string
    {
        return match ($this) {
            self::Concert => '공연',
            self::Doujin => '동인 행사',
            self::Expo => '기업 행사',
        };
    }
}
