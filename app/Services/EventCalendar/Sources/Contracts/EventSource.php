<?php

namespace App\Services\EventCalendar\Sources\Contracts;

use App\Services\EventCalendar\Sources\DTO\CollectedEventData;

/**
 * 행사 수집 드라이버 계약. 새 소스 추가 = 이 인터페이스 구현 + 커맨드 레지스트리 등록.
 */
interface EventSource
{
    /** 소스 코드(calendar_events.source 값). */
    public function code(): string;

    /**
     * 행사 수집. $skipKeys(이미 저장된 external_key)는 상세 요청 절약용 힌트 —
     * 드라이버 재량으로 무시 가능(아이템 수가 적은 소스는 전량 반환).
     *
     * @param  array<int, string>  $skipKeys
     * @return array<int, CollectedEventData>
     */
    public function collect(array $skipKeys = []): array;
}
