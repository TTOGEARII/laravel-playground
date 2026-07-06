<?php

namespace App\Services\SubcultureGameInfo\Raids\AlternativeParties;

use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 블루 아카이브 실전 편성 — 몰루로그 랭킹 API(ranks.baql.net) 클라이언트.
 * 제외 필터(excludeStudents)를 서버사이드로 지원하므로 그대로 프록시한다.
 *
 * 흐름: baql GraphQL(글로벌 시즌 일정)로 우리 Raid → 일본 시즌 인덱스/방어 타입 매핑
 *      → ranks API POST(protobuf 응답) → 경량 디코더로 파싱.
 * 실패(HTTP/파싱/매핑)는 전부 로그 + null 반환으로 폴백한다.
 */
class MollulogRanksClient
{
    /** 우리 raid_type(한국어) → ranks API raidType. 대결전은 몰루로그 URL(grand-assault)과 달리 API 는 elimination. */
    private const RAID_TYPES = [
        '총력전' => 'total_assault',
        '대결전' => 'elimination',
    ];

    /** 우리 tags.armor_type(한국어) → ranks API defenseType. */
    private const DEFENSE_TYPES = [
        '경장갑' => 'light',
        '중장갑' => 'heavy',
        '특수장갑' => 'special',
        '탄력장갑' => 'elastic',
    ];

    public function __construct(private MollulogRankDecoder $decoder) {}

    public function source(): string
    {
        return 'mollulog';
    }

    /**
     * 제외 캐릭터(uid 배열) 없이 클리어한 실전 편성을 가져온다.
     *
     * @param  list<string>  $excludeKeys  subculture_characters.external_key(= 몰루로그 uid) 배열
     * @return array{mode: string, total_count: int, parties: list<array>, source_url: ?string}|null 실패 시 null
     */
    public function findParties(Raid $raid, array $excludeKeys, int $page): ?array
    {
        $config = config('subculture-game-info.raids.alternative_parties');

        $raidType = self::RAID_TYPES[$raid->raid_type] ?? null;
        if ($raidType === null) {
            Log::warning('[SGI-ALT] 몰루로그 랭킹 미지원 레이드 종류', ['raid_id' => $raid->id, 'raid_type' => $raid->raid_type]);

            return null;
        }

        $schedule = $this->matchSchedule($raid, $raidType);
        $jpSeason = data_get($schedule, 'jpSchedule.seasonIndex');
        if ($schedule === null || $jpSeason === null) {
            Log::warning('[SGI-ALT] 몰루로그 시즌 매핑 실패', ['raid_id' => $raid->id, 'external_key' => $raid->external_key]);

            return null;
        }

        $defenseType = $this->resolveDefenseType($raid, $schedule);
        $decoded = $this->fetchRanks($raidType, (int) $jpSeason, $defenseType, $excludeKeys, $page, $config);
        if ($decoded === null) {
            return null;
        }

        return [
            'mode' => 'ranker', // 서버사이드 제외 필터라 항상 랭커(클리어) 단위
            'total_count' => $decoded['total_count'],
            'parties' => $this->toParties($decoded['ranks']),
            // 멀티웨이브가 파티 여러 장으로 펼쳐져 parties 수와 total_count(랭커 수) 단위가 다르다
            'has_more' => $page * (int) $config['per_page'] < (int) $decoded['total_count'],
            'source_url' => $raid->source_url,
        ];
    }

    /**
     * baql GraphQL 에서 글로벌 시즌 일정을 받아 우리 Raid 와 매칭한다.
     * 1순위: external_key(total-assault-83) → uid(gl_total_assault_83) 일치.
     * 2순위: 기간 겹침(+보스명 일치 우선).
     */
    private function matchSchedule(Raid $raid, string $raidType): ?array
    {
        $nodes = $this->fetchSchedules($raidType);
        if ($nodes === null) {
            return null;
        }

        $expectedUid = 'gl_'.str_replace('-', '_', (string) $raid->external_key);
        $byUid = collect($nodes)->first(fn (array $node) => ($node['uid'] ?? null) === $expectedUid);
        if ($byUid !== null) {
            return $byUid;
        }

        // 기간 겹침 후보 중 보스명 일치를 우선
        $overlapping = collect($nodes)->filter(function (array $node) use ($raid) {
            if ($raid->starts_at === null || $raid->ends_at === null || empty($node['startAt']) || empty($node['endAt'])) {
                return false;
            }

            return $raid->starts_at->lte($node['endAt']) && $raid->ends_at->gte($node['startAt']);
        });

        return $overlapping->first(fn (array $node) => data_get($node, 'raidBoss.name') === $raid->boss_name)
            ?? $overlapping->first();
    }

    /** GraphQL 일정 조회(1일 캐시). 실패 시 null. */
    private function fetchSchedules(string $raidType): ?array
    {
        $config = config('subculture-game-info.raids.alternative_parties');
        $query = sprintf(
            'query { raidSchedules(region: "gl", raidType: "%s") { nodes { uid seasonIndex startAt endAt raidBoss { name } defenseTypeSets { difficulty defenseTypes } jpSchedule { seasonIndex } } } }',
            $raidType,
        );

        return Cache::remember(
            "sgi:alt-party:mollulog:schedules:gl:{$raidType}",
            $config['mollulog']['schedule_cache_ttl'],
            function () use ($config, $query): ?array {
                try {
                    $response = Http::timeout($config['timeout'])
                        ->post($config['mollulog']['graphql_endpoint'], ['query' => $query]);

                    if ($response->failed()) {
                        Log::warning('[SGI-ALT] baql GraphQL 요청 실패', ['status' => $response->status()]);

                        return null;
                    }

                    return $response->json('data.raidSchedules.nodes');
                } catch (\Throwable $e) {
                    Log::warning('[SGI-ALT] baql GraphQL 요청 예외', ['error' => $e->getMessage()]);

                    return null;
                }
            },
        );
    }

    /**
     * 방어 타입: 우리 tags.armor_type(수집값)을 우선 매핑하고,
     * 없으면 일정 노드의 defenseTypeSets 첫 항목으로 폴백한다.
     */
    private function resolveDefenseType(Raid $raid, array $schedule): string
    {
        $armor = data_get($raid->tags, 'armor_type');
        if (is_string($armor) && isset(self::DEFENSE_TYPES[$armor])) {
            return self::DEFENSE_TYPES[$armor];
        }

        return (string) data_get($schedule, 'defenseTypeSets.0.defenseTypes.0', 'light');
    }

    /** ranks API 호출 + protobuf 디코딩(1시간 캐시 — 키에 정렬·정규화한 제외 목록 포함). */
    private function fetchRanks(string $raidType, int $season, string $defenseType, array $excludeKeys, int $page, array $config): ?array
    {
        $perPage = $config['per_page'];
        // 상한은 Form Request 가 1차로 막지만, 다른 경로(커맨드 등)에서 호출돼도 안전하게 재강제
        $exclude = collect($excludeKeys)->map(fn ($key) => (string) $key)->unique()->sort()->take(500)->values();
        $cacheKey = sprintf(
            'sgi:alt-party:mollulog:ranks:%s:%d:%s:%d:%d:%s',
            $raidType, $season, $defenseType, $page, $perPage, md5($exclude->implode(',')),
        );

        return Cache::remember($cacheKey, $config['mollulog']['ranks_cache_ttl'], function () use ($raidType, $season, $defenseType, $exclude, $page, $perPage, $config): ?array {
            $url = $config['mollulog']['ranks_endpoint'].'?'.http_build_query([
                'raidType' => $raidType,
                'season' => $season,
                'defenseType' => $defenseType,
            ]);
            $body = [
                'perPage' => $perPage,
                'page' => $page,
                'includeStudents' => [],
                'excludeStudents' => $exclude->map(fn (string $uid) => ['uid' => $uid, 'tiers' => []])->values()->all(),
            ];

            try {
                $response = Http::timeout($config['timeout'])
                    ->withBody(json_encode($body), 'application/json')
                    ->post($url);

                if ($response->failed()) {
                    Log::warning('[SGI-ALT] 몰루로그 ranks 요청 실패', ['status' => $response->status(), 'season' => $season]);

                    return null;
                }

                return $this->decoder->decode($response->body());
            } catch (\Throwable $e) {
                Log::warning('[SGI-ALT] 몰루로그 ranks 요청/파싱 실패', ['season' => $season, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * 디코딩된 랭킹을 공통 파티 형태로 변환.
     * 한 클리어(Rank)가 여러 웨이브(Party)면 "n위 m편성"으로 웨이브별 한 장씩 펼친다.
     *
     * @return list<array{rank: int, score: int, title: string, members: list<array>}>
     */
    private function toParties(array $ranks): array
    {
        $parties = [];

        foreach ($ranks as $rank) {
            $waveCount = count($rank['parties']);

            foreach ($rank['parties'] as $index => $slots) {
                $members = collect($slots)
                    ->filter() // 빈 슬롯(null) 제거
                    ->map(fn (array $student) => [
                        'external_key' => $student['uid'],
                        'fallback_name' => null,
                        'meta' => [
                            'level' => $student['level'],
                            'tier' => $student['tier'],
                            'weapon_tier' => $student['weapon_tier'],
                            'is_assist' => $student['is_assist'],
                        ],
                    ])
                    ->values()
                    ->all();

                $parties[] = [
                    'rank' => $rank['rank'],
                    'score' => $rank['score'],
                    'title' => $waveCount > 1 ? "{$rank['rank']}위 ".($index + 1).'편성' : "{$rank['rank']}위",
                    'members' => $members,
                ];
            }
        }

        return $parties;
    }
}
