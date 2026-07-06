<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\RaidQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function __construct(private RaidQueryService $query) {}

    /**
     * 게임별 캐릭터 마스터 목록 + 성장도 입력 스키마.
     * 쿼리: game(slug, 필수)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['game' => ['required', 'string']]);

        $game = Game::where('slug', $request->query('game'))->firstOrFail();
        $data = $this->query->listCharacters($game->id);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $data->count(),
                'growth_schema' => config("subculture-game-info.raids.growth_fields.{$game->slug}", []),
            ],
        ]);
    }
}
