<?php

namespace App\Http\Controllers\MiniGame;

use App\Http\Controllers\Controller;
use App\Services\MiniGame\GameCatalog;
use Illuminate\View\View;

class MainController extends Controller
{
    public function __construct(
        private GameCatalog $catalog
    ) {}

    public function index(): View
    {
        // 게임 목록은 카탈로그가 단일 출처. 외부 게임(external=true)은 랭킹 대상에서 제외한다.
        $games = array_map(fn ($game) => [
            ...$game,
            'status' => 'available',
            'rankable' => ! $game['external'],
        ], $this->catalog->all());

        return view('mini-game.index', compact('games'));
    }

    public function vampireSurvival(): View
    {
        return view('mini-game.vampire-survival.index');
    }

    public function tetris(): View
    {
        return view('mini-game.tetris.index');
    }

    public function doom(): View
    {
        return view('mini-game.doom.index');
    }
}
