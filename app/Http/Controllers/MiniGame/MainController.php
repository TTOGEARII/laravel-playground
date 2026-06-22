<?php

namespace App\Http\Controllers\MiniGame;

use App\Http\Controllers\Controller;
use App\Services\MiniGame\GameService;
use Illuminate\View\View;

class MainController extends Controller
{
    public function __construct(
        private GameService $gameService
    ) {}

    public function index(): View
    {
        $games = [
            [
                'id' => 1,
                'name' => '뱀파이어 서바이벌',
                'description' => '마지막 까지 살아남아보거라',
                'icon' => '🧛',
                'color' => 'accent-indigo',
                'tags' => ['서바이벌', '액션', '도전'],
                'status' => 'available',
                'route' => 'mini-game.vampire-survival.index',
            ],
            [
                'id' => 2,
                'name' => '테트리스',
                'description' => '블록을 쌓아 줄을 지우고 점수를 쌓아라. 홀드·티스핀까지!',
                'icon' => '🟦',
                'color' => 'accent-teal',
                'tags' => ['퍼즐', '고전', '중독성'],
                'status' => 'available',
                'route' => 'mini-game.tetris.index',
            ],
        ];

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
}
