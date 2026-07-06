<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Support\Collection;

/**
 * 레이드/캐릭터 조회 전용 서비스(화면·API 응답 조립).
 */
class RaidQueryService
{
    /**
     * 레이드 목록(게임/상태 필터). status 는 계산 속성이라 컬렉션 단계에서 거른다.
     *
     * @return Collection<int, array>
     */
    public function listRaids(?string $gameSlug = null, ?string $status = null): Collection
    {
        $raids = Raid::query()
            ->with('game:id,slug,name,icon,color')
            ->withCount(['parties', 'guidePosts'])
            ->when($gameSlug, fn ($q) => $q->whereHas('game', fn ($g) => $g->where('slug', $gameSlug)))
            ->orderByRaw('starts_at IS NULL') // 일정 미상은 뒤로
            ->orderByDesc('starts_at')
            ->get();

        return $raids
            ->when($status, fn ($c) => $c->filter(fn (Raid $r) => $r->status === $status)->values())
            ->map(fn (Raid $r) => $this->raidSummary($r));
    }

    /** 레이드 상세(편성 멤버·캐릭터·공략글 포함). */
    public function showRaid(Raid $raid): array
    {
        $raid->load([
            'game:id,slug,name,icon,color',
            'parties.members.character:id,external_key,name,rarity,traits,image_url,image_path',
            'guidePosts' => fn ($q) => $q->orderByDesc('posted_at'),
        ]);

        return array_merge($this->raidSummary($raid), [
            'note' => $raid->note,
            'parties' => $raid->parties->map(fn ($party) => [
                'id' => $party->id,
                'title' => $party->title,
                'difficulty' => $party->difficulty,
                'source' => $party->source,
                'source_url' => $party->source_url,
                'note' => $party->note,
                'members' => $party->members->map(fn ($member) => [
                    'slot_type' => $member->slot_type,
                    'sort' => $member->sort,
                    'note' => $member->note,
                    'character' => $member->character === null ? null : [
                        'id' => $member->character->id,
                        'external_key' => $member->character->external_key,
                        'name' => $member->character->name,
                        'rarity' => $member->character->rarity,
                        'traits' => $member->character->traits,
                        'image_url' => $member->character->display_image_url,
                    ],
                ])->values()->all(),
            ])->values()->all(),
            'guide_posts' => $raid->guidePosts->map(fn ($post) => [
                'source' => $post->source,
                'title' => $post->title,
                'url' => $post->url,
                'posted_at' => $post->posted_at?->toIso8601String(),
                'views' => $post->views,
            ])->values()->all(),
        ]);
    }

    /**
     * 게임별 활성 캐릭터 목록.
     *
     * @return Collection<int, array>
     */
    public function listCharacters(int $gameId): Collection
    {
        return Character::query()
            ->where('subculture_game_id', $gameId)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (Character $c) => [
                'id' => $c->id,
                'external_key' => $c->external_key,
                'name' => $c->name,
                'rarity' => $c->rarity,
                'traits' => $c->traits,
                'image_url' => $c->display_image_url,
            ]);
    }

    private function raidSummary(Raid $raid): array
    {
        return [
            'id' => $raid->id,
            'game' => [
                'slug' => $raid->game?->slug,
                'name' => $raid->game?->name,
                'icon' => $raid->game?->icon,
                'color' => $raid->game?->color,
            ],
            'name' => $raid->name,
            'boss_name' => $raid->boss_name,
            'raid_type' => $raid->raid_type,
            'tags' => $raid->tags,
            'starts_at' => $raid->starts_at?->toIso8601String(),
            'ends_at' => $raid->ends_at?->toIso8601String(),
            'status' => $raid->status,
            'source' => $raid->source,
            'source_url' => $raid->source_url,
            'parties_count' => $raid->parties_count ?? $raid->parties->count(),
            'guide_posts_count' => $raid->guide_posts_count ?? $raid->guidePosts->count(),
        ];
    }
}
