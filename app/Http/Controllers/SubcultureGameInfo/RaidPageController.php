<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Contracts\View\View;

/**
 * 레이드 정보 페이지(Vue 마운트). 데이터는 API 로 로드한다.
 */
class RaidPageController extends Controller
{
    public function index(): View
    {
        $slugs = config('subculture-game-info.raids.games', []);
        $games = Game::whereIn('slug', $slugs)
            ->where('active_flg', true)
            ->orderBy('sort')
            ->get(['slug', 'name', 'icon', 'color'])
            ->map(fn (Game $g) => [
                'slug' => $g->slug,
                'name' => $g->name,
                'icon' => $g->icon,
                'color' => $g->color,
            ])
            ->values();

        return view('subculture-game-info.raids', ['games' => $games]);
    }
}
