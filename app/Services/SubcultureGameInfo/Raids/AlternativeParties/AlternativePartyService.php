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
     * @param  list<string>  $excludeKeys  미보유(제외) 캐릭터 external_key 배열
     * @param  list<string>  $includeKeys  반드시 포함할 캐릭터 external_key 배열(AND)
     * @param  ?string  $difficulty  블아 총력전 전용 난이도(insane|torment|lunatic), null=전체
     * @param  ?string  $armor  블아 대결전 전용 장갑(경장갑 등), null=수집된 기본 장갑
     */
    public function findParties(Raid $raid, array $excludeKeys, array $includeKeys = [], int $page = 1, ?string $difficulty = null, ?string $armor = null): array
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
            $result = $client->findParties($raid, $excludeKeys, $includeKeys, max(1, $page), $difficulty, $armor);
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
                'armor' => $armor,
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
            'armor' => $armor,
            'source' => $client->source(),
            'source_url' => $result['source_url'],
        ];
    }

    /**
     * 학생별 출전 횟수(블아 전용).
     * - usage: external_key → {count, assist_count} 맵 (대체 후보 뱃지용, 프론트 조회)
     * - characters: 출전순 정렬 + 우리 마스터 조인(이름·이미지)한 핵심 캐릭터 목록 (요약 카드용)
     * - max_count: 최다 출전 수(요약 카드의 상대 막대 기준)
     * 실패는 빈 결과로 폴백(500 금지).
     *
     * @return array{supported: bool, usage?: array<string, array{count: int, assist_count: int}>, characters?: list<array>, max_count?: int}
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

        $usage ??= [];
        $characters = $this->joinUsageCharacters($raid, $usage);

        return [
            'supported' => true,
            'usage' => $usage,
            'characters' => $characters,
            'max_count' => $characters[0]['count'] ?? 0,
        ];
    }

    /**
     * 출전 통계(uid → count)를 우리 캐릭터 마스터와 조인해 출전순으로 정렬한다(N+1 없이 일괄 조회).
     * 마스터에 없는 uid(콜라보 등)는 이름을 못 붙이므로 제외한다.
     *
     * @param  array<string, array{count: int, assist_count: int}>  $usage
     * @return list<array{external_key: string, name: string, rarity: ?string, image_url: ?string, count: int, assist_count: int}>
     */
    private function joinUsageCharacters(Raid $raid, array $usage): array
    {
        if ($usage === []) {
            return [];
        }

        $characters = Character::query()
            ->where('subculture_game_id', $raid->subculture_game_id)
            ->whereIn('external_key', array_keys($usage))
            ->get()
            ->keyBy('external_key');

        return collect($usage)
            ->map(fn (array $stat, string $key) => [
                'external_key' => $key,
                'character' => $characters->get($key),
                'count' => $stat['count'],
                'assist_count' => $stat['assist_count'],
            ])
            ->filter(fn (array $row) => $row['character'] !== null)
            ->sortByDesc('count')
            ->map(fn (array $row) => [
                'external_key' => $row['external_key'],
                'name' => $row['character']->name,
                'rarity' => $row['character']->rarity,
                'image_url' => $row['character']->display_image_url,
                'count' => $row['count'],
                'assist_count' => $row['assist_count'],
            ])
            ->values()
            ->all();
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
                            // partial 모드(니케)에서 미보유 니케 흐림 표시용 — 없으면 false
                            'is_excluded' => (bool) ($member['is_excluded'] ?? false),
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
