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
                    'ticket_opens_on' => self::parseTicketOpensOn($dto->ticketOpenText, $dto->startsOn),
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

    /**
     * 티켓오픈 원문("7월 7일 (화) 오후 4시" — 연도 없음)에서 오픈일을 파싱한다.
     * 연도 보간: 공연일 연도로 두되, 그 날짜가 공연일보다 뒤면(연말 오픈→연초 공연) 전년으로 본다.
     */
    public static function parseTicketOpensOn(?string $ticketOpenText, ?string $startsOn): ?string
    {
        if ($ticketOpenText === null || $startsOn === null
            || ! preg_match('/(?:(\d{4})\s*년\s*)?(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $ticketOpenText, $m)) {
            return null;
        }
        $startYear = (int) substr($startsOn, 0, 4);
        $year = $m[1] !== '' ? (int) $m[1] : $startYear;
        $date = sprintf('%04d-%02d-%02d', $year, $m[2], $m[3]);
        if ($m[1] === '' && $date > $startsOn) {
            $date = sprintf('%04d-%02d-%02d', $year - 1, $m[2], $m[3]);
        }

        return $date;
    }
}
