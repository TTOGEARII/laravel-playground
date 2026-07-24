<?php

namespace App\Services\EventCalendar\Sources\DTO;

use App\Enums\EventCalendar\EventKind;

/**
 * 소스 드라이버가 수집한 행사 1건의 전달 객체. EventSyncService 가 DB 에 동기화한다.
 */
readonly class CollectedEventData
{
    /**
     * @param  array<int, array{label: string, url: string}>  $ticketLinks
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $source,
        public string $externalKey,
        public EventKind $kind,
        public string $title,
        public ?string $startsOn,       // Y-m-d (파싱 실패 시 null → sync 에서 스킵+로그)
        public ?string $endsOn = null,  // Y-m-d
        public ?string $timeText = null,
        public ?string $venue = null,
        public ?string $priceText = null,
        public ?string $ticketOpenText = null,
        public array $ticketLinks = [],
        public array $extra = [],
        public ?string $posterUrl = null,
        public ?string $detailUrl = null,
    ) {}
}
