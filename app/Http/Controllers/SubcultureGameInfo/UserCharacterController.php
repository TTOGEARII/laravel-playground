<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubcultureGameInfo\UpdateUserCharacterRequest;
use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\UserCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 내 캐릭터 풀(보유+성장도) — 로그인 사용자 전용(세션 인증).
 * 비로그인 사용자는 클라이언트 localStorage 를 쓴다(동일 JSON 계약).
 */
class UserCharacterController extends Controller
{
    public function __construct(private UserCharacterService $service) {}

    /** 내 풀 목록. 쿼리: game(slug) */
    public function index(Request $request): JsonResponse
    {
        $gameId = null;
        if ($request->filled('game')) {
            $gameId = Game::where('slug', $request->query('game'))->firstOrFail()->id;
        }

        $data = $this->service->poolFor($request->user(), $gameId);

        return response()->json(['data' => $data, 'meta' => ['total' => $data->count()]]);
    }

    /** 보유+성장도 저장(멱등 upsert). */
    public function update(UpdateUserCharacterRequest $request, Character $character): JsonResponse
    {
        $saved = $this->service->upsert(
            $request->user(),
            $character,
            $request->boolean('owned'),
            $request->validated('growth'),
        );

        return response()->json(['data' => [
            'character_id' => $saved->subculture_character_id,
            'owned' => $saved->owned_flg,
            'growth' => $saved->growth,
        ]]);
    }

    /** 보유 해제(기록 삭제). */
    public function destroy(Request $request, Character $character): JsonResponse
    {
        $this->service->remove($request->user(), $character);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** JSON 파일 내보내기. 쿼리: game(slug, 필수) */
    public function export(Request $request): JsonResponse
    {
        $request->validate(['game' => ['required', 'string']]);
        $game = Game::where('slug', $request->query('game'))->firstOrFail();

        $filename = "my-characters-{$game->slug}-".now()->format('Ymd').'.json';

        return response()
            ->json($this->service->export($request->user(), $game))
            ->header('Content-Disposition', "attachment; filename={$filename}");
    }

    /** JSON 가져오기(게스트 localStorage 포맷과 동일 계약). */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', 'string'],
            'characters' => ['required', 'array', 'max:2000'],
        ]);

        $game = Game::where('slug', $validated['game'])->firstOrFail();
        $stats = $this->service->import($request->user(), $game, $validated['characters']);

        return response()->json(['data' => $stats]);
    }
}
