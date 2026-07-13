<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Banner;
use App\Models\SubcultureGameInfo\Character;
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
        $rows = Banner::forGame($gameId)
            ->where('scope', $scope)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now())) // 종료된 픽업은 제외
            ->orderBy('starts_at')
            ->get();

        $chars = $this->characterIndex($gameId, $rows);

        return $rows->map(fn (Banner $b) => $this->bannerData($b, $chars));
    }

    /**
     * 배너 featured 학생을 캐릭터 마스터로 보강하기 위한 (external_key → Character) 인덱스.
     * 픽업 카드에 속성(공격/방어/구분) 배지와 몰루로그 캐시 이미지를 붙인다.
     *
     * @param  Collection<int, Banner>  $rows
     * @return Collection<string, Character>
     */
    private function characterIndex(int $gameId, Collection $rows): Collection
    {
        $keys = $rows->flatMap(fn (Banner $b) => collect($b->featured ?? [])->pluck('external_key'))
            ->filter()->unique()->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        return Character::where('subculture_game_id', $gameId)
            ->whereIn('external_key', $keys)
            ->get()
            ->keyBy('external_key');
    }

    /** 진행중 컨텐츠(이벤트) — 기본 현재 scope. kind 필터(예: event 만). */
    public function events(int $gameId, string $scope = 'current', ?string $kind = null): Collection
    {
        return GameEvent::forGame($gameId)
            ->where('scope', $scope)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now())) // 종료된 이벤트는 제외
            ->orderBy('starts_at')
            ->get()
            ->map(fn (GameEvent $e) => $this->eventData($e));
    }

    /** 미래시 — forecast 배너 + 이벤트를 시작일 기준 통합 타임라인. */
    public function futureTimeline(int $gameId): Collection
    {
        $bannerRows = Banner::forGame($gameId)->where('scope', 'forecast')->get();
        $chars = $this->characterIndex($gameId, $bannerRows);
        $banners = $bannerRows->map(fn (Banner $b) => ['row' => 'banner'] + $this->bannerData($b, $chars));

        $events = GameEvent::forGame($gameId)->where('scope', 'forecast')->get()
            ->map(fn (GameEvent $e) => ['row' => 'event'] + $this->eventData($e));

        return $banners->concat($events)
            ->sortBy(fn ($x) => $x['starts_at'] ?? '9999')
            ->values();
    }

    /** @param  Collection<string, Character>  $chars */
    private function bannerData(Banner $b, Collection $chars): array
    {
        $collectionBase = rtrim((string) config('subculture-game-info.raids.schaledb.collection_image_base'), '/');

        $featured = collect($b->featured ?? [])->map(function (array $f) use ($chars, $collectionBase) {
            $c = $chars->get($f['external_key'] ?? null);
            $traits = $c ? (array) $c->traits : [];
            $key = $f['external_key'] ?? null;

            return [
                'external_key' => $key,
                'name' => $f['name'] ?? null,
                'rarity' => $f['rarity'] ?? ($traits['star'] ?? null), // 성급은 캐릭터 마스터로 폴백
                'rerun' => (bool) ($f['rerun'] ?? false), // 복각 배지(몰루로그 소스)
                // 픽업 카드용 전신 일러(몰루로그와 동일 collection 소스), 폴백은 캐시/소스 이미지
                'image' => ($key !== null && $collectionBase !== '')
                    ? "{$collectionBase}/{$key}.webp"
                    : ($c?->display_image_url ?? ($f['image'] ?? null)),
                // 픽업 카드용 속성 배지(공격/방어/구분)
                'attributes' => array_values(array_filter([
                    $traits['bullet'] ?? null,
                    $traits['armor'] ?? null,
                    $traits['squad'] ?? null,
                ])),
            ];
        })->all();

        return [
            'id' => $b->id,
            'scope' => $b->scope,
            'kind' => $b->kind,
            'title' => $b->title,
            'featured' => $featured,
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
