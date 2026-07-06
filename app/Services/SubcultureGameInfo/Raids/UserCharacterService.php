<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\UserCharacter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 로그인 사용자의 캐릭터 풀(보유+성장도) 도메인 로직.
 * JSON 내보내기/가져오기 계약은 게스트 localStorage 와 동일 포맷을 쓴다
 * (비로그인 → 로그인 이전이 import 한 번으로 끝나도록).
 */
class UserCharacterService
{
    /**
     * 내 풀 목록(캐릭터 id 키). game 지정 시 해당 게임만.
     *
     * @return Collection<int, array>
     */
    public function poolFor(User $user, ?int $gameId = null): Collection
    {
        return UserCharacter::query()
            ->where('user_id', $user->id)
            ->when($gameId, fn ($q) => $q->whereHas('character', fn ($c) => $c->where('subculture_game_id', $gameId)))
            ->get()
            ->map(fn (UserCharacter $uc) => [
                'character_id' => $uc->subculture_character_id,
                'owned' => $uc->owned_flg,
                'growth' => $uc->growth,
            ]);
    }

    public function upsert(User $user, Character $character, bool $owned, ?array $growth): UserCharacter
    {
        return UserCharacter::updateOrCreate(
            ['user_id' => $user->id, 'subculture_character_id' => $character->id],
            ['owned_flg' => $owned, 'growth' => $this->sanitizeGrowth($character, $growth)],
        );
    }

    public function remove(User $user, Character $character): void
    {
        UserCharacter::where('user_id', $user->id)
            ->where('subculture_character_id', $character->id)
            ->delete();
    }

    /** JSON 내보내기 페이로드(게스트 localStorage 와 동일 계약, version 1). */
    public function export(User $user, Game $game): array
    {
        $rows = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('character', fn ($c) => $c->where('subculture_game_id', $game->id))
            ->with('character:id,external_key,name')
            ->get();

        return [
            'version' => 1,
            'game' => $game->slug,
            'exported_at' => now()->toIso8601String(),
            'characters' => $rows->map(fn (UserCharacter $uc) => [
                'external_key' => $uc->character->external_key,
                'name' => $uc->character->name,
                'owned' => $uc->owned_flg,
                'growth' => $uc->growth,
            ])->values()->all(),
        ];
    }

    /**
     * JSON 가져오기. external_key → name 순으로 캐릭터를 매칭하고 트랜잭션으로 upsert.
     *
     * @return array{imported: int, missing: int}
     */
    public function import(User $user, Game $game, array $characters): array
    {
        $index = Character::where('subculture_game_id', $game->id)->get(['id', 'external_key', 'name']);
        $byKey = $index->keyBy('external_key');
        $byName = $index->keyBy('name');

        $stats = ['imported' => 0, 'missing' => 0];

        DB::transaction(function () use ($user, $game, $characters, $byKey, $byName, &$stats) {
            foreach ($characters as $entry) {
                if (! is_array($entry)) {
                    $stats['missing']++;

                    continue;
                }
                $character = $byKey->get((string) ($entry['external_key'] ?? ''))
                    ?? $byName->get((string) ($entry['name'] ?? ''));
                if ($character === null) {
                    $stats['missing']++;

                    continue;
                }

                $fullCharacter = $character->setRelation('game', $game);
                $this->upsert(
                    $user,
                    $fullCharacter,
                    (bool) ($entry['owned'] ?? true),
                    is_array($entry['growth'] ?? null) ? $entry['growth'] : null,
                );
                $stats['imported']++;
            }
        });

        return $stats;
    }

    /**
     * growth 를 config growth_fields 정의(허용 키)로 걸러 저장한다.
     * (import 등 검증을 안 거친 경로에서도 스키마 밖 키가 들어가지 않도록)
     */
    private function sanitizeGrowth(Character $character, ?array $growth): ?array
    {
        if ($growth === null || $growth === []) {
            return null;
        }

        $slug = $character->game?->slug ?? Game::find($character->subculture_game_id)?->slug;
        $allowed = array_column(config("subculture-game-info.raids.growth_fields.{$slug}", []), 'key');
        $filtered = array_intersect_key($growth, array_flip($allowed));

        return $filtered === [] ? null : $filtered;
    }
}
