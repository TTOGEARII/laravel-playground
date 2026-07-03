<?php

namespace App\Services\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use Illuminate\Database\Eloquent\Collection;

class RedeemCodeService
{
    /** 필터용 게임 목록(노출 + 정렬순). */
    public function gamesForFilter(): Collection
    {
        return Game::where('active_flg', true)->orderBy('sort')->get();
    }

    /**
     * 게임별 사용 가능한 코드를 교차검증 여부로 나눠 반환.
     * verified(API active 또는 2개+ 출처)는 메인, 나머지(단일 출처 미검증)는 보조.
     *
     * @return array<int, array{game: Game, verified: \Illuminate\Support\Collection, unverified: \Illuminate\Support\Collection}>
     */
    public function grouped(string|array|null $slug = null): array
    {
        // 단일 slug(문자열)·다중(배열)·전체(null) 모두 허용. 빈 값은 제외.
        $slugs = array_values(array_filter((array) $slug, fn ($s) => $s !== null && $s !== ''));

        $games = Game::where('active_flg', true)
            ->orderBy('sort')
            ->when(! empty($slugs), fn ($q) => $q->whereIn('slug', $slugs))
            ->with(['codes' => function ($q) {
                $q->usable()
                    ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'unverified' THEN 1 ELSE 2 END")
                    ->orderByDesc('corroboration_count')
                    ->orderByDesc('found_at');
            }])
            ->get();

        return $games->map(function (Game $game) {
            $hasApi = $this->gameHasApi($game->slug);

            // 메인 노출 기준:
            //  - API 게임(호요버스): API로 active 확정 또는 2개+ 출처 교차검증 또는 미래 만료일
            //  - API 없는 게임(블아/명조/트릭컬): 정리 사이트(aggregator) 출처면 노출(검증할 API가 없음)
            $isMain = function (RedeemCode $c) use ($hasApi) {
                if ($c->is_verified) {
                    return true;
                }
                if ($c->expires_at !== null && $c->expires_at->isFuture()) {
                    return true;
                }

                return ! $hasApi && $c->source_type === SourceType::Aggregator;
            };

            return [
                'game' => $game,
                // 메인도 과도하게 길지 않게 상한(이미 active→교차검증→최신 순 정렬)
                'verified' => $game->codes->filter($isMain)->take(30)->values(),
                // 미검증(보조)은 너무 길어지지 않게 상한
                'unverified' => $game->codes->reject($isMain)->take(40)->values(),
            ];
        })->all();
    }

    /** 해당 게임이 권위 API(ennead/seria) 소스를 가지는가. */
    private function gameHasApi(string $slug): bool
    {
        foreach (config("subculture-game-info.games.{$slug}.sources", []) as $spec) {
            if (in_array($spec['driver'] ?? '', ['ennead', 'seria'], true)) {
                return true;
            }
        }

        return false;
    }
}
