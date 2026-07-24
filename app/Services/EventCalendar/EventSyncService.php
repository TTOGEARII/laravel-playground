<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Support\Facades\Log;

/**
 * 수집 DTO 를 calendar_events 에 동기화. (source, external_key) 기준 멱등 upsert.
 */
class EventSyncService
{
    /**
     * @param  array<int, CollectedEventData>  $collected
     * @return array{created: int, updated: int, skipped: int}
     */
    public function sync(array $collected): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($collected as $dto) {
            if ($dto->startsOn === null) {
                $stats['skipped']++;
                Log::info('행사 시작일 없음 — 스킵', ['source' => $dto->source, 'key' => $dto->externalKey, 'title' => $dto->title]);

                continue;
            }

            $event = Event::updateOrCreate(
                ['source' => $dto->source, 'external_key' => $dto->externalKey],
                [
                    'kind' => $dto->kind,
                    'title' => $dto->title,
                    'starts_on' => $dto->startsOn,
                    'ends_on' => $dto->endsOn,
                    'time_text' => $dto->timeText,
                    'venue' => $dto->venue,
                    'price_text' => $dto->priceText,
                    'ticket_open_text' => $dto->ticketOpenText,
                    'ticket_links' => $dto->ticketLinks ?: null,
                    'extra' => $dto->extra ?: null,
                    'poster_url' => $dto->posterUrl,
                    'detail_url' => $dto->detailUrl,
                    'active_flg' => true,
                ],
            );
            $stats[$event->wasRecentlyCreated ? 'created' : 'updated']++;
        }

        return $stats;
    }

    /**
     * 소스의 기존 external_key 목록(드라이버 상세 요청 절약용).
     *
     * @return array<int, string>
     */
    public function knownKeys(string $source): array
    {
        return Event::where('source', $source)->pluck('external_key')->all();
    }
}
