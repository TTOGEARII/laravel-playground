<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\MollulogRanksClient;
use Illuminate\Support\Facades\Log;

/**
 * 블아 대결전 편성 보정 — 대결전은 보스가 장갑 3종으로 나와 장갑마다 별도 편성이 필요하다.
 * 사이드카(몰루로그 DOM)가 가져온 단일 목록 편성을, baql 랭킹 API 의 장갑 타입별
 * 상위 편성으로 갈아끼운다(파티 note=장갑 — UI 가 장갑별 섹션으로 그룹핑).
 */
class EliminationPartyService
{
    public function __construct(
        private MollulogRanksClient $ranks,
        private RaidSyncService $sync,
    ) {}

    /** @return int 장갑별 편성으로 갈아끼운 대결전 레이드 수 */
    public function sync(Game $game): int
    {
        $raids = Raid::where('subculture_game_id', $game->id)
            ->where('raid_type', '대결전')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->subDay()))
            ->get();

        $updated = 0;
        foreach ($raids as $raid) {
            $byArmor = $this->ranks->topPartiesByDefenseType($raid);
            if ($byArmor === null) {
                Log::info('[SGI-RAID] 대결전 장갑별 편성 조회 실패 — 기존 편성 유지', ['raid_id' => $raid->id]);

                continue;
            }

            $parties = [];
            $sort = 0;
            foreach ($byArmor as $armor => $armorParties) {
                foreach ($armorParties as $party) {
                    $parties[] = [
                        'title' => "{$armor} {$party['title']}",
                        'difficulty' => null,
                        'sort' => $sort++,
                        'source_url' => $raid->source_url,
                        'note' => $armor, // UI 장갑별 그룹핑 키
                        'members' => collect($party['members'])->values()->map(fn (array $m, int $i) => [
                            'external_key' => $m['external_key'],
                            'name' => '',
                            'slot_type' => data_get($m, 'meta.is_assist') ? '조력자' : null,
                            'sort' => $i,
                            'note' => null,
                        ])->all(),
                    ];
                }
            }

            // 레이드 자체 필드는 그대로 두고 편성만 갈아끼운다(sync 계약 재사용, manual 편성 보존)
            $this->sync->sync($game, 'mollulog', [[
                'external_key' => $raid->external_key,
                'name' => $raid->name,
                'boss_name' => $raid->boss_name,
                'raid_type' => $raid->raid_type,
                'tags' => $raid->tags,
                'starts_at' => $raid->starts_at?->toDateString(),
                'ends_at' => $raid->ends_at?->toDateString(),
                'source_url' => $raid->source_url,
                'parties' => $parties,
            ]]);
            $updated++;
        }

        return $updated;
    }
}
