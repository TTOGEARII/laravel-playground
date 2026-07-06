<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\DTO\CrawledRaidData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 크롤/수동 레이드 데이터를 DB 에 동기화한다.
 * 레이드는 (게임, external_key) upsert, 편성은 같은 source 것만 갈아끼운다
 * (source='manual' 편성은 크롤 sync 에도 보존). 멤버는 external_key → name 순으로
 * 캐릭터를 매칭하고, 못 찾으면 로그 후 스킵한다.
 */
class RaidSyncService
{
    /**
     * @param  array<int, array>  $items  사이드카/수동 JSON items
     * @return array{raids: int, parties: int, members: int, missing_members: int, skipped: int}
     */
    public function sync(Game $game, string $source, array $items): array
    {
        $stats = ['raids' => 0, 'parties' => 0, 'members' => 0, 'missing_members' => 0, 'skipped' => 0];

        // 캐릭터 매칭 인덱스(external_key 우선, name 폴백)
        $characters = Character::where('subculture_game_id', $game->id)->get(['id', 'external_key', 'name']);
        $byKey = $characters->keyBy('external_key');
        $byName = $characters->keyBy('name');

        foreach ($items as $item) {
            $dto = is_array($item) ? CrawledRaidData::fromArray($item) : null;
            if ($dto === null) {
                $stats['skipped']++;

                continue;
            }

            DB::transaction(function () use ($game, $source, $dto, $byKey, $byName, &$stats) {
                $raid = Raid::updateOrCreate(
                    ['subculture_game_id' => $game->id, 'external_key' => $dto->externalKey],
                    [
                        'name' => $dto->name,
                        'boss_name' => $dto->bossName,
                        'raid_type' => $dto->raidType,
                        'tags' => $dto->tags,
                        'starts_at' => $dto->startsAt,
                        'ends_at' => $dto->endsAt,
                        'source' => $source,
                        'source_url' => $dto->sourceUrl,
                    ],
                );
                $stats['raids']++;

                // 같은 source 의 기존 편성만 재구성(수동 편성 보존). 멤버는 FK cascade 로 정리.
                $raid->parties()->where('source', $source)->delete();

                foreach ($dto->parties as $partyDto) {
                    $party = $raid->parties()->create([
                        'title' => $partyDto->title,
                        'difficulty' => $partyDto->difficulty,
                        'sort' => $partyDto->sort,
                        'source' => $source,
                        'source_url' => $partyDto->sourceUrl,
                        'note' => $partyDto->note,
                    ]);
                    $stats['parties']++;

                    foreach ($partyDto->members as $member) {
                        $character = $byKey->get($member['external_key']) ?? $byName->get($member['name']);
                        if ($character === null) {
                            $stats['missing_members']++;
                            Log::info('[SGI-RAID] 편성 멤버 캐릭터 미매칭', [
                                'game' => $game->slug, 'raid' => $dto->externalKey,
                                'key' => $member['external_key'], 'name' => $member['name'],
                            ]);

                            continue;
                        }
                        $party->members()->create([
                            'subculture_character_id' => $character->id,
                            'slot_type' => $member['slot_type'],
                            'sort' => $member['sort'],
                        ]);
                        $stats['members']++;
                    }
                }
            });
        }

        return $stats;
    }
}
