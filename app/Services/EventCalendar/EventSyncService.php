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

            // 교차 소스 공연 병합: 같은 공연이 여러 소스(festivallife·라운지·수동 등)에 올라올 수 있다.
            // 신규 생성 시 같은 시작일·다른 소스 공연과 아티스트 토큰이 겹치면
            // ① festivallife(상세 전문)가 나중에 오면 기존 행을 festivallife 상세로 '승격'(행 유지 —
            //    딥링크·jpop 장르 보존, 기존 예매 링크는 상세에 없으면 이어받음)
            // ② 그 외 소스가 나중이면 기존 유지 + 빈 예매 링크만 보강 후 스킵.
            if ($dto->kind === \App\Enums\EventCalendar\EventKind::Concert
                && ! Event::where('source', $dto->source)->where('external_key', $dto->externalKey)->exists()
                && ($dup = $this->findDuplicateConcert($dto)) !== null) {
                if ($dto->source === 'festivallife') {
                    $dup->update([
                        'source' => $dto->source,
                        'external_key' => $dto->externalKey,
                        'title' => $dto->title,
                        'starts_on' => $dto->startsOn,
                        'ends_on' => $dto->endsOn,
                        'time_text' => $dto->timeText,
                        'venue' => $dto->venue ?: $dup->venue,
                        'price_text' => $dto->priceText,
                        'ticket_open_text' => $dto->ticketOpenText,
                        'ticket_opens_on' => self::parseTicketOpensOn($dto->ticketOpenText, $dto->startsOn),
                        'ticket_links' => $dto->ticketLinks ?: $dup->ticket_links,
                        'extra' => ($dto->extra ?: []) + (array) $dup->extra,
                        'poster_url' => $dto->posterUrl ?: $dup->poster_url,
                        'detail_url' => $dto->detailUrl,
                    ]);
                    $stats['updated']++;
                } else {
                    if (empty($dup->ticket_links) && $dto->ticketLinks !== []) {
                        $dup->update(['ticket_links' => $dto->ticketLinks]);
                    }
                    $stats['skipped']++;
                }

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

    /** 같은 시작일의 다른 소스 공연 중 아티스트 토큰(한/영 2자+)이 겹치는 행을 찾는다(병합 대상). */
    private function findDuplicateConcert(CollectedEventData $dto): ?Event
    {
        $tokens = self::artistTokens($dto->title);
        if ($tokens === []) {
            return null;
        }
        $sameDay = Event::where('kind', 'concert')
            ->whereDate('starts_on', $dto->startsOn) // date 캐스트가 00:00:00 을 붙여 등호 비교는 실패(SQLite)
            ->where('source', '!=', $dto->source)
            ->get();
        foreach ($sameDay as $event) {
            if (array_intersect($tokens, self::artistTokens($event->title)) !== []) {
                return $event;
            }
        }

        return null;
    }

    /** 제목에서 아티스트 식별 토큰만 추출(공연 상용어 제거, 소문자, 2자+). J-pop 레퍼런스 대조에도 사용. */
    public static function artistTokens(string $title): array
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
        if ($ticketOpenText === null || $startsOn === null) {
            return null;
        }
        // 팬클럽 선예매·일반예매가 함께 적힌 경우 '일반예매' 날짜 기준(모두가 예매 가능한 시점)
        if (($pos = mb_strrpos($ticketOpenText, '일반')) !== false) {
            $general = mb_substr($ticketOpenText, $pos);
            if (preg_match('/\d{1,2}\s*월\s*\d{1,2}\s*일/u', $general)) {
                $ticketOpenText = $general;
            }
        }
        if (! preg_match('/(?:(\d{4})\s*년\s*)?(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $ticketOpenText, $m)) {
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
