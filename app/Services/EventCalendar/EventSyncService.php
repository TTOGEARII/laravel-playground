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

            // 교차 소스 공연 중복 방지: 같은 공연이 festivallife 와 큐레이션 캘린더(jpoptistory) 양쪽에
            // 올라온다 — 신규 생성 시에만, 같은 시작일의 다른 소스 공연과 아티스트 토큰이 겹치면 스킵.
            // (기존 행 갱신은 (source, external_key) 경로라 영향 없음)
            if ($dto->kind === \App\Enums\EventCalendar\EventKind::Concert
                && ! Event::where('source', $dto->source)->where('external_key', $dto->externalKey)->exists()
                && $this->duplicateConcertExists($dto)) {
                $stats['skipped']++;

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
            // 큐레이션 소스의 확정 장르(jpop 등)는 그대로 반영 — null 이면 기존 태그 유지(Gemini 담당)
            if ($dto->genre !== null && $event->genre !== $dto->genre) {
                $event->update(['genre' => $dto->genre]);
            }
            $stats[$event->wasRecentlyCreated ? 'created' : 'updated']++;
        }

        return $stats;
    }

    /** 같은 시작일의 다른 소스 공연과 아티스트 토큰(한/영 2자+)이 겹치는가. */
    private function duplicateConcertExists(CollectedEventData $dto): bool
    {
        $tokens = self::artistTokens($dto->title);
        if ($tokens === []) {
            return false;
        }
        $sameDay = Event::where('kind', 'concert')
            ->whereDate('starts_on', $dto->startsOn) // date 캐스트가 00:00:00 을 붙여 등호 비교는 실패(SQLite)
            ->where('source', '!=', $dto->source)
            ->pluck('title');
        foreach ($sameDay as $title) {
            if (array_intersect($tokens, self::artistTokens($title)) !== []) {
                return true;
            }
        }

        return false;
    }

    /** 제목에서 아티스트 식별 토큰만 추출(공연 상용어 제거, 소문자, 2자+). */
    private static function artistTokens(string $title): array
    {
        static $stop = ['내한공연', '내한', '공연', '콘서트', '단독', '라이브', 'live', 'in', 'seoul', 'korea', 'tour', 'asia', 'world', 'the', 'concert', '단독공연', '정보'];
        $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title));
        $tokens = [];
        foreach (preg_split('/\s+/u', $normalized) as $t) {
            if (mb_strlen($t) >= 2 && ! in_array($t, $stop, true) && ! preg_match('/^\d+$/', $t)) {
                $tokens[] = $t;
            }
        }

        return $tokens;
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
