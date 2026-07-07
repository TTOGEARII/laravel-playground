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
 *   1차(ranker)  — 5개 스쿼드 전부가 제외 니케를 안 쓰는 랭커만.
 *   2차(partial) — 1차 결과가 min_ranker_results 미만이면, 미보유가 적은 랭커의
 *                  "전체 클리어(1~5부대)"를 부대를 빼지 않고 그대로 보여준다
 *                  (미보유 니케는 멤버에 is_excluded 표시 → 프론트에서 흐리게).
 *                  부대를 개별로 발췌하면 1·3부대만 남는 식으로 구성이 끊겨 참고 가치가 떨어진다.
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
     * 제외 니케 없이(+포함 니케를 모두 넣어) 클리어한 실전 편성을 가져온다.
     * 레츠도로는 서버 필터 API 가 없어 랭킹 전체를 받아 우리 쪽에서 거른다.
     *
     * @param  list<string>  $excludeKeys  제외할 external_key(= nikkeId/nameCode) 배열
     * @param  list<string>  $includeKeys  반드시 포함할 external_key 배열(AND)
     * @return array{mode: string, total_count: int, parties: list<array>, source_url: ?string}|null 실패 시 null
     */
    public function findParties(Raid $raid, array $excludeKeys, array $includeKeys, int $page, ?string $difficulty = null): ?array
    {
        // 니케 솔로 레이드는 난이도 구분이 없어 difficulty 는 무시한다(시그니처 통일용)
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

        $filtered = $this->filterRankings($rankings, $excludeKeys, $includeKeys, (int) $config['min_ranker_results']);

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
     * 랭킹을 제외/포함 니케 기준으로 거른다. 화이트박스 테스트를 위해 공개 메서드로 둔다.
     * 반환 groups 는 페이지네이션 단위 묶음 — ranker 모드는 랭커별 파티 5장, squad 모드는 스쿼드 1장.
     *
     * @param  list<array>  $rankings  레츠도로 rankings 배열(rank/totalDamage/squads)
     * @param  list<string>  $excludeKeys
     * @param  list<string>  $includeKeys  반드시 포함할 니케(랭커의 스쿼드 어딘가에 전부 등장)
     * @return array{mode: string, groups: list<list<array{rank: int, score: int, title: string, members: list<array>}>>}
     */
    public function filterRankings(array $rankings, array $excludeKeys, array $includeKeys, int $minRankerResults): array
    {
        // 상한은 Form Request 가 1차로 막지만, 다른 경로에서 호출돼도 안전하게 재강제
        $exclude = collect($excludeKeys)->map(fn ($key) => (string) $key)->unique()->take(500)->flip();
        $include = collect($includeKeys)->map(fn ($key) => (string) $key)->unique()->take(500)->values();

        $squadClean = fn (array $squad) => collect($squad['nikkes'] ?? [])
            ->every(fn (array $nikke) => ! $exclude->has((string) ($nikke['nikkeId'] ?? '')));

        // 포함 필터: 랭커의 전체 스쿼드(니케 집합)에 포함 니케가 모두 있어야 한다
        $rankerHasIncludes = function (array $ranker) use ($include): bool {
            if ($include->isEmpty()) {
                return true;
            }
            $used = collect($ranker['squads'] ?? [])
                ->flatMap(fn (array $squad) => collect($squad['nikkes'] ?? [])->pluck('nikkeId'))
                ->map(fn ($id) => (string) $id)
                ->flip();

            return $include->every(fn (string $key) => $used->has($key));
        };

        // 1차: 랭커 단위 — 모든 스쿼드가 깨끗하고 포함 니케를 모두 갖춘 랭커의 전체 클리어
        $cleanRankers = collect($rankings)
            ->filter(fn (array $ranker) => collect($ranker['squads'] ?? [])->isNotEmpty()
                && collect($ranker['squads'])->every($squadClean)
                && $rankerHasIncludes($ranker))
            ->values();

        if ($cleanRankers->count() >= $minRankerResults) {
            $groups = $cleanRankers
                ->map(fn (array $ranker) => collect($ranker['squads'])
                    ->map(fn (array $squad) => $this->toParty($ranker, $squad, $exclude))
                    ->values()
                    ->all())
                ->all();

            return ['mode' => 'ranker', 'groups' => $groups];
        }

        // 2차: 부분 매칭 — 내 풀과 잘 맞는 랭커부터, 부대를 빼지 않고 전체 클리어(1~5부대)를 보여준다.
        // 미보유 니케가 든 부대도 그대로 두고 멤버에 is_excluded 만 표시한다(프론트에서 흐리게,
        // 사용자가 직접 대체 캐릭터를 지정해 채울 수 있다). 전 부대 오염 랭커도 버리지 않는다 —
        // 보유가 적은 사용자에게 빈 화면 대신 "대체로 채울 목표"를 주는 편이 낫다.
        // 스쿼드를 개별 발췌하면 "1위 1·3부대"처럼 구성이 군데군데 끊겨 참고 가치가 떨어진다.
        $groups = collect($rankings)
            ->filter(fn (array $ranker) => collect($ranker['squads'] ?? [])->isNotEmpty() && $rankerHasIncludes($ranker))
            ->map(fn (array $ranker) => [
                'clean_count' => collect($ranker['squads'])->filter($squadClean)->count(),
                'excluded_count' => collect($ranker['squads'])
                    ->flatMap(fn (array $squad) => $squad['nikkes'] ?? [])
                    ->filter(fn (array $nikke) => $exclude->has((string) ($nikke['nikkeId'] ?? '')))
                    ->count(),
                'ranker' => $ranker,
            ])
            // 깨끗한 부대 많은 순 → 미보유 멤버 적은 순 → 상위 랭크 순
            ->sort(fn (array $a, array $b) => [$b['clean_count'], $a['excluded_count'], $a['ranker']['rank'] ?? 0]
                <=> [$a['clean_count'], $b['excluded_count'], $b['ranker']['rank'] ?? 0])
            ->map(fn (array $row) => collect($row['ranker']['squads'])
                ->map(fn (array $squad) => $this->toParty($row['ranker'], $squad, $exclude))
                ->values()
                ->all())
            ->values()
            ->all();

        return ['mode' => 'partial', 'groups' => $groups];
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

    /**
     * 랭커의 스쿼드 하나를 공통 파티 형태로 변환.
     * partial 모드에서 미보유 니케를 프론트가 흐리게 표시할 수 있게 멤버에 is_excluded 를 심는다.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $exclude  제외 external_key flip 맵
     */
    private function toParty(array $ranker, array $squad, \Illuminate\Support\Collection $exclude): array
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
                    'is_excluded' => $exclude->has((string) ($nikke['nikkeId'] ?? '')),
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
