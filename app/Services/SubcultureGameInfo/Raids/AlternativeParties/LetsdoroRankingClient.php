<?php

namespace App\Services\SubcultureGameInfo\Raids\AlternativeParties;

use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 니케 실전 편성 — 레츠도로 솔로 레이드 랭킹 API(api3.letsdoro.com) 클라이언트.
 * 원본 API 에 제외 필터가 없으므로 랭킹 전체를 받아 우리 쪽에서 거른다.
 *
 * 필터 2단계:
 *   1차(ranker) — 5개 스쿼드 전부가 제외 니케를 안 쓰는 랭커만.
 *   2차(squad)  — 1차 결과가 min_ranker_results 미만이면 제외 니케 없는 개별 스쿼드로 보강.
 *
 * 실패(HTTP/시즌 매핑)는 로그 + null 폴백.
 * TODO: 프로덕션 IP 에서 Cloudflare 차단이 확인되면 Playwright 사이드카 폴백 추가.
 */
class LetsdoroRankingClient
{
    public function source(): string
    {
        return 'letsdoro';
    }

    /**
     * 제외 니케(nikkeId 배열) 없이 클리어한 실전 편성을 가져온다.
     *
     * @param  list<string>  $excludeKeys  subculture_characters.external_key(= nikkeId/nameCode) 배열
     * @return array{mode: string, total_count: int, parties: list<array>, source_url: ?string}|null 실패 시 null
     */
    public function findParties(Raid $raid, array $excludeKeys, int $page): ?array
    {
        $config = config('subculture-game-info.raids.alternative_parties');

        $season = $this->matchSeason($raid);
        if ($season === null) {
            Log::warning('[SGI-ALT] 레츠도로 시즌 매핑 실패', ['raid_id' => $raid->id, 'external_key' => $raid->external_key]);

            return null;
        }

        $rankings = $this->fetchRankings((int) $season['id'], $config);
        if ($rankings === null) {
            return null;
        }

        $filtered = $this->filterRankings($rankings, $excludeKeys, (int) $config['min_ranker_results']);

        // 페이지 단위: ranker 모드=랭커(클리어 1건), squad 모드=개별 스쿼드
        $pageGroups = $this->paginate($filtered['groups'], $page, (int) $config['per_page']);

        return [
            'mode' => $filtered['mode'],
            'total_count' => count($filtered['groups']),
            'parties' => collect($pageGroups)->flatten(1)->values()->all(),
            // 랭커 1건이 파티 여러 장으로 펼쳐져 parties 수와 total_count 단위가 다르다 —
            // "더 보기" 판단은 개수 비교가 아니라 이 플래그로 한다.
            'has_more' => $page * (int) $config['per_page'] < count($filtered['groups']),
            'source_url' => $raid->source_url,
        ];
    }

    /**
     * 랭킹을 제외 니케 기준으로 거른다. 화이트박스 테스트를 위해 공개 메서드로 둔다.
     * 반환 groups 는 페이지네이션 단위 묶음 — ranker 모드는 랭커별 파티 5장, squad 모드는 스쿼드 1장.
     *
     * @param  list<array>  $rankings  레츠도로 rankings 배열(rank/totalDamage/squads)
     * @param  list<string>  $excludeKeys
     * @return array{mode: string, groups: list<list<array{rank: int, score: int, title: string, members: list<array>}>>}
     */
    public function filterRankings(array $rankings, array $excludeKeys, int $minRankerResults): array
    {
        // 상한은 Form Request 가 1차로 막지만, 다른 경로에서 호출돼도 안전하게 재강제
        $exclude = collect($excludeKeys)->map(fn ($key) => (string) $key)->unique()->take(500)->flip();

        $squadClean = fn (array $squad) => collect($squad['nikkes'] ?? [])
            ->every(fn (array $nikke) => ! $exclude->has((string) ($nikke['nikkeId'] ?? '')));

        // 1차: 랭커 단위 — 모든 스쿼드가 깨끗한 랭커의 전체 클리어를 그대로 보여준다
        $cleanRankers = collect($rankings)
            ->filter(fn (array $ranker) => collect($ranker['squads'] ?? [])->isNotEmpty()
                && collect($ranker['squads'])->every($squadClean))
            ->values();

        if ($cleanRankers->count() >= $minRankerResults) {
            $groups = $cleanRankers
                ->map(fn (array $ranker) => collect($ranker['squads'])
                    ->map(fn (array $squad) => $this->toParty($ranker, $squad))
                    ->values()
                    ->all())
                ->all();

            return ['mode' => 'ranker', 'groups' => $groups];
        }

        // 2차: 스쿼드 단위 — 개별 스쿼드 중 제외 니케 없는 것만 모아 보강
        $groups = collect($rankings)
            ->flatMap(fn (array $ranker) => collect($ranker['squads'] ?? [])
                ->filter(fn (array $squad) => ! empty($squad['nikkes']) && $squadClean($squad))
                ->map(fn (array $squad) => [$this->toParty($ranker, $squad)]))
            ->values()
            ->all();

        return ['mode' => 'squad', 'groups' => $groups];
    }

    /**
     * 우리 Raid(external_key: soloraid-{seasonNumber})를 레츠도로 시즌과 매칭한다.
     * 1순위: seasonNumber 일치, 2순위: 기간(startDate~endDate) 겹침.
     */
    private function matchSeason(Raid $raid): ?array
    {
        $seasons = $this->fetchSeasons();
        if ($seasons === null) {
            return null;
        }

        if (preg_match('/^soloraid-(\d+)$/', (string) $raid->external_key, $m) === 1) {
            $byNumber = collect($seasons)->first(fn (array $season) => (int) ($season['seasonNumber'] ?? -1) === (int) $m[1]);
            if ($byNumber !== null) {
                return $byNumber;
            }
        }

        return collect($seasons)->first(function (array $season) use ($raid) {
            if ($raid->starts_at === null || $raid->ends_at === null || empty($season['startDate']) || empty($season['endDate'])) {
                return false;
            }

            return $raid->starts_at->toDateString() <= $season['endDate']
                && $raid->ends_at->toDateString() >= $season['startDate'];
        });
    }

    /** 시즌 목록 조회(캐시). 실패 시 null. */
    private function fetchSeasons(): ?array
    {
        $config = config('subculture-game-info.raids.alternative_parties');

        return Cache::remember(
            'sgi:alt-party:letsdoro:seasons',
            $config['letsdoro']['cache_ttl'],
            fn (): ?array => $this->getJson($config['letsdoro']['endpoint'].'/seasons', $config['timeout']),
        );
    }

    /** 시즌 랭킹 조회(캐시 — 원본이 no-cache 라 우리 쪽 캐시 필수). 실패 시 null. */
    private function fetchRankings(int $seasonId, array $config): ?array
    {
        $server = $config['letsdoro']['server'];
        $data = Cache::remember(
            "sgi:alt-party:letsdoro:ranking:{$seasonId}:{$server}",
            $config['letsdoro']['cache_ttl'],
            fn (): ?array => $this->getJson(
                $config['letsdoro']['endpoint']."/seasons/{$seasonId}/ranking?server={$server}",
                $config['timeout'],
            ),
        );

        return $data === null ? null : ($data['rankings'] ?? []);
    }

    /** GET JSON 공통 처리 — 실패는 로그 + null. */
    private function getJson(string $url, int $timeout): ?array
    {
        try {
            $response = Http::timeout($timeout)->acceptJson()->get($url);

            if ($response->failed()) {
                Log::warning('[SGI-ALT] 레츠도로 요청 실패', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('[SGI-ALT] 레츠도로 요청 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** 랭커의 스쿼드 하나를 공통 파티 형태로 변환. */
    private function toParty(array $ranker, array $squad): array
    {
        $rank = (int) ($ranker['rank'] ?? 0);
        $squadNumber = (int) ($squad['squadNumber'] ?? 0);

        return [
            'rank' => $rank,
            'score' => (int) ($squad['damage'] ?? 0),
            'title' => "{$rank}위 {$squadNumber}부대",
            'members' => collect($squad['nikkes'] ?? [])
                ->map(fn (array $nikke) => [
                    'external_key' => (string) ($nikke['nikkeId'] ?? ''),
                    'fallback_name' => $nikke['nikkeName'] ?? null,
                    'meta' => [],
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return list<array> */
    private function paginate(array $entries, int $page, int $perPage): array
    {
        return array_slice($entries, max(0, $page - 1) * $perPage, $perPage);
    }
}
