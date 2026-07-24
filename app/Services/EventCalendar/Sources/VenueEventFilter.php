<?php

namespace App\Services\EventCalendar\Sources;

use App\Enums\EventCalendar\EventKind;

/**
 * 전시장 캘린더(킨텍스·SETEC·코엑스) 공통 서브컬쳐 판별 — config event-calendar.venues 단일 출처.
 * 산업 전시가 대부분이라 포지티브 필터(키워드 or 주최 화이트리스트)만 통과시킨다.
 */
class VenueEventFilter
{
    /** 행사명(또는 주최)이 서브컬쳐로 판별되는가. */
    public function isSubculture(string $title, ?string $host = null): bool
    {
        foreach ((array) config('event-calendar.venues.keywords', []) as $kw) {
            if (mb_stripos($title, $kw) !== false) {
                return true;
            }
        }
        if ($host !== null) {
            foreach ((array) config('event-calendar.venues.hosts', []) as $h) {
                if (mb_stripos($host, $h) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 전용 소스가 담당하는 행사(코믹월드·일러스타·문구전)인가 — 전시장 수집에서 제외(중복 방지). */
    public function isDedupe(string $title): bool
    {
        foreach ((array) config('event-calendar.venues.dedupe_keywords', []) as $kw) {
            if (mb_stripos($title, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 행사명으로 종류 판별(콘서트/동인/그 외 기업행사). */
    public function kindFor(string $title): EventKind
    {
        $map = (array) config('event-calendar.venues.kind_keywords', []);
        foreach ((array) ($map['concert'] ?? []) as $kw) {
            if (mb_stripos($title, $kw) !== false) {
                return EventKind::Concert;
            }
        }
        foreach ((array) ($map['doujin'] ?? []) as $kw) {
            if (mb_stripos($title, $kw) !== false) {
                return EventKind::Doujin;
            }
        }

        return EventKind::Expo;
    }
}
