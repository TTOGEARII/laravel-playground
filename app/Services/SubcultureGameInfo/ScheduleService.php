<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Banner;
use App\Models\SubcultureGameInfo\GameEvent;
use Illuminate\Support\Collection;

/**
 * 정보검색 대시보드의 일정 데이터(배너·이벤트·미래시) 조회 도메인 로직.
 * 진행중/예정 먼저, 종료는 뒤로 정렬한다.
 */
class ScheduleService
{
    /** 모집중 학생(배너) — 기본 현재 scope. 진행중·예정 먼저. */
    public function banners(int $gameId, string $scope = 'current'): Collection
    {
        return Banner::forGame($gameId)
            ->where('scope', $scope)
            ->orderByRaw('(ends_at IS NOT NULL AND ends_at < ?)', [now()]) // 종료된 건 뒤로(DB 무관)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Banner $b) => $this->bannerData($b));
    }

    /** 진행중 컨텐츠(이벤트) — 기본 현재 scope. kind 필터(예: event 만). */
    public function events(int $gameId, string $scope = 'current', ?string $kind = null): Collection
    {
        return GameEvent::forGame($gameId)
            ->where('scope', $scope)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->orderByRaw('(ends_at IS NOT NULL AND ends_at < ?)', [now()])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (GameEvent $e) => $this->eventData($e));
    }

    /** 미래시 — forecast 배너 + 이벤트를 시작일 기준 통합 타임라인. */
    public function futureTimeline(int $gameId): Collection
    {
        $banners = Banner::forGame($gameId)->where('scope', 'forecast')->get()
            ->map(fn (Banner $b) => ['row' => 'banner'] + $this->bannerData($b));

        $events = GameEvent::forGame($gameId)->where('scope', 'forecast')->get()
            ->map(fn (GameEvent $e) => ['row' => 'event'] + $this->eventData($e));

        return $banners->concat($events)
            ->sortBy(fn ($x) => $x['starts_at'] ?? '9999')
            ->values();
    }

    private function bannerData(Banner $b): array
    {
        return [
            'id' => $b->id,
            'scope' => $b->scope,
            'kind' => $b->kind,
            'title' => $b->title,
            'featured' => $b->featured ?? [],
            'starts_at' => $b->starts_at?->toIso8601String(),
            'ends_at' => $b->ends_at?->toIso8601String(),
            'status' => $b->status,
        ];
    }

    private function eventData(GameEvent $e): array
    {
        return [
            'id' => $e->id,
            'scope' => $e->scope,
            'kind' => $e->kind,
            'title' => $e->title,
            'image_url' => $e->image_url,
            'link_url' => $e->link_url,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'status' => $e->status,
        ];
    }
}
