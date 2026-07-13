<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Contracts\View\View;

/**
 * 정보검색 페이지(mollulog 스타일 대시보드, Vue 마운트). 데이터는 API 로 로드한다.
 */
class InfoPageController extends Controller
{
    public function index(): View
    {
        // 정보검색에 노출할 게임 = modules 가 정의된 게임(config 단일 출처, 배열 순서 = 탭 순서).
        // 레이드 게임(블아·니케·트릭컬·브더2) + 호요버스(학정보만) 등을 모두 포괄한다.
        $slugs = array_keys((array) config('subculture-game-info.raids.modules', []));
        $bySlug = Game::whereIn('slug', $slugs)
            ->where('active_flg', true)
            ->get(['slug', 'name', 'icon', 'color'])
            ->keyBy('slug');

        $games = collect($slugs)
            ->filter(fn (string $slug) => $bySlug->has($slug))
            ->map(fn (string $slug) => [
                'slug' => $bySlug[$slug]->slug,
                'name' => $bySlug[$slug]->name,
                'icon' => $bySlug[$slug]->icon,
                'color' => $bySlug[$slug]->color,
                // 게임별 정보 모듈(렌더 순서) — 게임마다 다른 정보 구성을 서버가 결정한다
                'modules' => config("subculture-game-info.raids.modules.{$slug}"),
            ])
            ->values();

        return view('subculture-game-info.info', ['games' => $games]);
    }
}
