<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\DTO\CrawledCharacterData;
use Illuminate\Support\Facades\Log;

/**
 * 크롤된 캐릭터 마스터를 DB 에 동기화한다. (게임, external_key) 기준 upsert.
 * 이번 수집에 없던 캐릭터는 삭제하지 않고 소프트 비활성(active_flg=false)하되,
 * 수집량이 기존 활성 수 대비 임계치(deactivate_guard_ratio) 미만이면
 * 마크업 깨짐으로 보고 비활성화를 건너뛴다(전체 비활성 사고 방지).
 */
class CharacterSyncService
{
    /**
     * @param  array<int, array>  $items  사이드카 JSON items
     * @return array{created: int, updated: int, deactivated: int, skipped: int}
     */
    public function sync(Game $game, string $source, array $items): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0];

        $activeBefore = $game->characters()->where('active_flg', true)->count();
        $seenKeys = [];

        foreach ($items as $item) {
            $dto = is_array($item) ? CrawledCharacterData::fromArray($item) : null;
            if ($dto === null) {
                $stats['skipped']++;

                continue;
            }
            $seenKeys[] = $dto->externalKey;

            $character = Character::updateOrCreate(
                ['subculture_game_id' => $game->id, 'external_key' => $dto->externalKey],
                [
                    'name' => $dto->name,
                    'rarity' => $dto->rarity,
                    'traits' => $dto->traits,
                    'image_url' => $dto->imageUrl,
                    'source' => $source,
                    'source_url' => $dto->sourceUrl,
                    'active_flg' => true,
                ],
            );

            $character->wasRecentlyCreated ? $stats['created']++ : ($character->wasChanged() ? $stats['updated']++ : null);
        }

        // 미등장 캐릭터 소프트 비활성 (수집량 급감 시 가드)
        $guardRatio = (float) config('subculture-game-info.raids.crawler.deactivate_guard_ratio', 0.5);
        if ($seenKeys !== [] && ($activeBefore === 0 || count($seenKeys) >= $activeBefore * $guardRatio)) {
            $stats['deactivated'] = $game->characters()
                ->where('active_flg', true)
                ->where('source', $source)
                ->whereNotIn('external_key', $seenKeys)
                ->update(['active_flg' => false]);
        } elseif ($seenKeys !== []) {
            Log::warning('[SGI-RAID] 수집량 급감 — 캐릭터 비활성화 스킵', [
                'game' => $game->slug, 'collected' => count($seenKeys), 'active_before' => $activeBefore,
            ]);
        }

        return $stats;
    }
}
