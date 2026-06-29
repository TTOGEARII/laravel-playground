<?php

namespace App\Services\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Database\Eloquent\Collection;

class RedeemCodeService
{
    /** 필터용 게임 목록(노출 + 정렬순). */
    public function gamesForFilter(): Collection
    {
        return Game::where('active_flg', true)->orderBy('sort')->get();
    }

    /**
     * 게임별로 사용 가능한 코드를 메인/커뮤니티로 나눠 반환.
     *
     * @return array<int, array{game: Game, main: \Illuminate\Support\Collection, community: \Illuminate\Support\Collection}>
     */
    public function grouped(?string $slug = null): array
    {
        $games = Game::where('active_flg', true)
            ->orderBy('sort')
            ->when($slug, fn ($q) => $q->where('slug', $slug))
            ->with(['codes' => function ($q) {
                $q->usable()
                    ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'unverified' THEN 1 ELSE 2 END")
                    ->orderByDesc('found_at');
            }])
            ->get();

        return $games->map(fn (Game $game) => [
            'game' => $game,
            'main' => $game->codes->filter(fn ($c) => $c->source_type === SourceType::Aggregator)->values(),
            'community' => $game->codes->filter(fn ($c) => $c->source_type === SourceType::Community)->values(),
        ])->all();
    }
}
