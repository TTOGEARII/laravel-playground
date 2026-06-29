<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Services\SubcultureGameInfo\RedeemCodeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MainController extends Controller
{
    public function __construct(private RedeemCodeService $codeService) {}

    public function index(Request $request): View
    {
        $selected = $request->query('game');
        $games = $this->codeService->gamesForFilter();

        // 잘못된 slug 는 전체로 폴백
        if ($selected !== null && ! $games->firstWhere('slug', $selected)) {
            $selected = null;
        }

        return view('subculture-game-info.index', [
            'games' => $games,
            'selected' => $selected,
            'groups' => $this->codeService->grouped($selected),
        ]);
    }
}
