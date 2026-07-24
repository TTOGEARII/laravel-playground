<?php

namespace App\Services\EventCalendar;

use App\Models\EventCalendar\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * 행사 캘린더 조회 도메인 로직(월 그리드·다가오는 행사·상세).
 */
class EventService
{
    /**
     * 해당 월과 겹치는 행사 목록(여러 날 행사는 기간 겹침 기준).
     *
     * @param  ?string  $kind  concert|doujin|expo|events(동인+기업)|null(전체)
     * @param  bool  $jpopOnly  공연(concert)만 J-pop 으로 제한(행사류는 영향 없음)
     */
    public function monthEvents(int $year, int $month, ?string $kind = null, bool $jpopOnly = false): Collection
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        return $this->filtered($kind, $jpopOnly)
            ->where('starts_on', '<=', $end->toDateString())
            ->where(function (Builder $q) use ($start) {
                $q->where('ends_on', '>=', $start->toDateString())
                    ->orWhere(function (Builder $qq) use ($start) {
                        $qq->whereNull('ends_on')->where('starts_on', '>=', $start->toDateString());
                    });
            })
            ->orderBy('starts_on')
            ->get();
    }

    /** 오늘 이후(진행 중 포함) 다가오는 행사. */
    public function upcoming(int $limit = 10, ?string $kind = null, bool $jpopOnly = false): Collection
    {
        $today = Carbon::today()->toDateString();

        return $this->filtered($kind, $jpopOnly)
            ->where(function (Builder $q) use ($today) {
                $q->where('starts_on', '>=', $today)->orWhere('ends_on', '>=', $today);
            })
            ->orderBy('starts_on')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?Event
    {
        return Event::where('active_flg', true)->find($id);
    }

    private function filtered(?string $kind, bool $jpopOnly): Builder
    {
        return Event::query()
            ->where('active_flg', true)
            ->when($kind === 'events', fn (Builder $q) => $q->whereIn('kind', ['doujin', 'expo']))
            ->when(in_array($kind, ['concert', 'doujin', 'expo'], true), fn (Builder $q) => $q->where('kind', $kind))
            // J-pop 필터: 공연은 jpop 태그만, 행사류(동인·기업)는 그대로 노출.
            // 미태깅(genre null) 공연은 아직 분류 전이라 보수적으로 노출(다음 수집에서 태깅됨).
            ->when($jpopOnly, fn (Builder $q) => $q->where(function (Builder $qq) {
                $qq->where('kind', '!=', 'concert')
                    ->orWhere('genre', 'jpop')
                    ->orWhereNull('genre');
            }));
    }
}
