<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\AttributePartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 속성(성격)별 추천 조합 조회 — 트릭컬 전용(config attribute_parties.games).
 */
class AttributePartyController extends Controller
{
    public function __construct(private AttributePartyService $service) {}

    /** 쿼리: game(slug, 필수). 미지원 게임은 supported=false. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['game' => ['required', 'string', 'max:50']]);
        $slug = (string) $request->query('game');

        if (! in_array($slug, (array) config('subculture-game-info.raids.attribute_parties.games', []), true)) {
            return response()->json(['data' => ['supported' => false, 'groups' => []]]);
        }

        $game = Game::where('slug', $slug)->firstOrFail();
        $groups = $this->service->list($game);

        return response()->json([
            'data' => ['supported' => true, 'groups' => $groups],
            'meta' => ['total' => $groups->sum(fn (array $group) => count($group['parties']))],
        ]);
    }
}
