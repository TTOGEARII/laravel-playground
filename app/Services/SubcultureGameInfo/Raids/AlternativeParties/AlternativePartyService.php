<?php

namespace App\Services\SubcultureGameInfo\Raids\AlternativeParties;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Support\Facades\Log;

/**
 * 미보유 캐릭터 제외 실전 편성 — 게임별 원본 랭킹 사이트 프록시의 진입점.
 * 게임별 클라이언트(블아=몰루로그, 니케=레츠도로)를 고르고,
 * 결과 멤버를 우리 캐릭터 마스터와 (game, external_key)로 조인해 응답을 조립한다.
 * 어떤 실패도 500 없이 supported:false 또는 빈 parties 로 폴백한다.
 */
class AlternativePartyService
{
    public function __construct(
        private MollulogRanksClient $mollulog,
        private LetsdoroRankingClient $letsdoro,
    ) {}

    /**
     * @param  list<string>  $excludeKeys  미보유 캐릭터 external_key 배열
     * @param  ?string  $difficulty  블아 전용 난이도(insane|torment|lunatic), null=전체
     */
    public function findParties(Raid $raid, array $excludeKeys, int $page = 1, ?string $difficulty = null): array
    {
        $raid->loadMissing('game');

        $client = match ($raid->game?->slug) {
            'bluearchive' => $this->mollulog,
            'nikke' => $this->letsdoro,
            default => null, // 트릭컬/브더2 등은 원본 랭킹 소스가 없어 미지원
        };

        if ($client === null) {
            return ['supported' => false];
        }

        try {
            $result = $client->findParties($raid, $excludeKeys, max(1, $page), $difficulty);
        } catch (\Throwable $e) {
            Log::warning('[SGI-ALT] 실전 편성 조회 실패', ['raid_id' => $raid->id, 'error' => $e->getMessage()]);
            $result = null;
        }

        // 외부 실패/매핑 실패 — 지원 게임이므로 supported 는 유지하고 빈 결과로 폴백
        if ($result === null) {
            return [
                'supported' => true,
                'mode' => null,
                'total_count' => 0,
                'parties' => [],
                'has_more' => false,
                'difficulty' => $difficulty,
                'source' => $client->source(),
                'source_url' => null,
            ];
        }

        return [
            'supported' => true,
            'mode' => $result['mode'],
            'total_count' => $result['total_count'],
            'parties' => $this->joinCharacters($raid, $result['parties']),
            'has_more' => (bool) ($result['has_more'] ?? false),
            'difficulty' => $difficulty,
            'source' => $client->source(),
            'source_url' => $result['source_url'],
        ];
    }

    /**
     * 학생별 출전 횟수(블아 전용) — 대체 캐릭터 후보에 실전 채용 빈도를 붙이는 용도.
     * 실패는 빈 usage 로 폴백(500 금지).
     *
     * @return array{supported: bool, usage?: array<string, array{count: int, assist_count: int}>}
     */
    public function studentUsage(Raid $raid): array
    {
        $raid->loadMissing('game');
        if ($raid->game?->slug !== 'bluearchive') {
            return ['supported' => false];
        }

        try {
            $usage = $this->mollulog->studentUsage($raid);
        } catch (\Throwable $e) {
            Log::warning('[SGI-ALT] 출전 통계 조회 실패', ['raid_id' => $raid->id, 'error' => $e->getMessage()]);
            $usage = null;
        }

        return ['supported' => true, 'usage' => $usage ?? []];
    }

    /**
     * 파티 멤버를 우리 캐릭터 마스터와 조인한다(일괄 조회로 N+1 방지).
     * 마스터에 없는 멤버는 원본 이름(fallback_name)만 노출한다.
     */
    private function joinCharacters(Raid $raid, array $parties): array
    {
        $keys = collect($parties)
            ->flatMap(fn (array $party) => array_column($party['members'], 'external_key'))
            ->unique()
            ->values();

        $characters = Character::query()
            ->where('subculture_game_id', $raid->subculture_game_id)
            ->whereIn('external_key', $keys)
            ->get()
            ->keyBy('external_key');

        return collect($parties)
            ->map(fn (array $party) => [
                'rank' => $party['rank'],
                'score' => $party['score'],
                'title' => $party['title'],
                'members' => collect($party['members'])
                    ->map(function (array $member) use ($characters) {
                        /** @var Character|null $character */
                        $character = $characters->get($member['external_key']);

                        return [
                            'external_key' => $member['external_key'],
                            'name' => $character?->name ?? $member['fallback_name'],
                            'rarity' => $character?->rarity,
                            'traits' => $character?->traits,
                            'image_url' => $character?->display_image_url,
                            'meta' => $member['meta'],
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
