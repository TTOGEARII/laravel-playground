<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\UserSubstitute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 내 대체 캐릭터 매핑(미보유 → 내 보유) — 로그인 사용자 전용(세션 인증).
 * 비로그인 사용자는 클라이언트 localStorage 를 쓴다(동일 { character_key: substitute_key } 계약).
 * 캐릭터는 external_key 로 참조 — 마스터 재수집에도 매핑이 유지된다.
 */
class UserSubstituteController extends Controller
{
    /** 게임별 내 대체 매핑. 쿼리: game(slug, 필수) → { character_key: substitute_key } */
    public function index(Request $request): JsonResponse
    {
        $game = $this->resolveGame($request);

        $map = UserSubstitute::query()
            ->where('user_id', $request->user()->id)
            ->where('subculture_game_id', $game->id)
            ->pluck('substitute_key', 'character_key');

        return response()->json(['data' => $map]);
    }

    /** 대체 지정(멱등 upsert) — 미보유 캐릭터당 1명, 다시 지정하면 교체. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', 'string', 'max:50'],
            'character_key' => ['required', 'string', 'max:100'],
            'substitute_key' => ['required', 'string', 'max:100', 'different:character_key'],
        ]);
        $game = $this->resolveGame($request);

        $row = UserSubstitute::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'subculture_game_id' => $game->id,
                'character_key' => $validated['character_key'],
            ],
            ['substitute_key' => $validated['substitute_key']],
        );

        return response()->json(['data' => [
            'character_key' => $row->character_key,
            'substitute_key' => $row->substitute_key,
        ]]);
    }

    /** 대체 해제. 바디: game, character_key */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', 'string', 'max:50'],
            'character_key' => ['required', 'string', 'max:100'],
        ]);
        $game = $this->resolveGame($request);

        UserSubstitute::query()
            ->where('user_id', $request->user()->id)
            ->where('subculture_game_id', $game->id)
            ->where('character_key', $validated['character_key'])
            ->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function resolveGame(Request $request): Game
    {
        return Game::where('slug', $request->input('game', $request->query('game')))->firstOrFail();
    }
}
