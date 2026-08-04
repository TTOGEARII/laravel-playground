<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\MollulogRanksClient;
use Illuminate\Support\Facades\Log;

/**
 * 블아 총력전 추천 편성 — baql 랭킹 API(MollulogRanksClient)로 상위 랭커 편성을 채운다.
 *
 * 왜 필요한가: 총력전 편성은 원래 사이드카 크롤러(몰루로그 /raids 리다이렉트 → 현재 회차 /ranks DOM)만
 * 채웠는데, /raids 리다이렉트가 "현재 진행 중" 에만 동작하고 회차 사이 공백기·주말 랭킹 지연이면 그 회차를
 * 통째로 놓쳐 편성이 0개가 되곤 했다. 대결전(EliminationPartyService)처럼 랭킹 API 를 시즌 단위로 직접
 * 조회하면 진행 중이든 최근 종료든 안정적으로 편성을 확보한다. (랭킹 API 데이터는 findParties 로 검증됨.)
 */
class TotalAssaultPartyService
{
    /** 시작한 지 이 일수 이내에 종료된 회차까지 백필(그보다 오래된 회차는 재조회 안 함 — API 절약). */
    private const BACKFILL_DAYS = 45;

    /** 회차당 채울 상위 편성 최대 개수(랭커 상위부터). */
    private const MAX_PARTIES = 10;

    public function __construct(
        private MollulogRanksClient $ranks,
        private RaidSyncService $sync,
    ) {}

    /** @return int 편성을 채운 총력전 레이드 수 */
    public function sync(Game $game): int
    {
        $raids = Raid::where('subculture_game_id', $game->id)
            ->where('raid_type', '총력전')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())               // 시작한 회차만 랭킹이 존재
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->subDays(self::BACKFILL_DAYS)))
            ->withCount('parties')
            ->get();

        $updated = 0;
        foreach ($raids as $raid) {
            $ongoing = $raid->ends_at === null || $raid->ends_at->isFuture();
            // 진행 중 회차는 매번 갱신(랭킹이 쌓이므로), 과거 회차는 편성이 비었을 때만 백필
            if ($raid->parties_count > 0 && ! $ongoing) {
                continue;
            }

            $result = $this->ranks->findParties($raid, [], [], 1);
            if ($result === null || empty($result['parties'])) {
                Log::info('[SGI-RAID] 총력전 랭킹 편성 조회 실패/없음 — 기존 편성 유지', ['raid_id' => $raid->id, 'external_key' => $raid->external_key]);

                continue;
            }

            $parties = collect($result['parties'])
                ->take(self::MAX_PARTIES)
                ->values()
                ->map(fn (array $party, int $sort) => [
                    'title' => $party['title'],
                    'difficulty' => data_get($raid->tags, 'mollulog.armors.0.difficulty'),
                    'sort' => $sort,
                    'source_url' => $raid->source_url,
                    'note' => null,
                    'members' => collect($party['members'])->values()->map(fn (array $m, int $i) => [
                        'external_key' => $m['external_key'],
                        'name' => '',
                        'slot_type' => data_get($m, 'meta.is_assist') ? '조력자' : null,
                        'sort' => $i,
                        'note' => null,
                    ])->all(),
                ])->all();

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
