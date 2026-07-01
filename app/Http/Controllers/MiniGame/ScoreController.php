<?php

namespace App\Http\Controllers\MiniGame;

use App\Http\Controllers\Controller;
use App\Services\MiniGame\GameCatalog;
use App\Services\MiniGame\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * 미니게임 점수 등록/랭킹 조회 API.
 * 비로그인: 닉네임을 직접 입력 / 로그인: 회원 닉네임을 강제로 사용(입력값 무시).
 */
class ScoreController extends Controller
{
    public function __construct(
        private GameService $gameService,
        private GameCatalog $catalog,
    ) {}

    /** 점수 등록 → 저장 후 순위 + 해당 게임 랭킹 반환. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'game' => ['required', 'string', Rule::in($this->catalog->rankableKeys())],
            'score' => ['required', 'integer', 'min:0', 'max:100000000'],
            'nickname' => ['nullable', 'string', 'max:20'],
        ]);

        $nickname = $this->resolveNickname($data['nickname'] ?? null);

        $result = $this->gameService->submitScore(
            gameKey: $data['game'],
            nickname: $nickname,
            score: $data['score'],
            userId: Auth::id(),
        );

        return response()->json(['data' => $result]);
    }

    /** 홈 팝업용 — 전체(랭킹 대상) 게임 랭킹. */
    public function all(): JsonResponse
    {
        return response()->json(['data' => $this->gameService->allRankings()]);
    }

    /**
     * 저장에 쓸 닉네임 결정.
     * 로그인 사용자는 회원명을 강제 사용하고, 게스트는 입력값(공백/태그 정리, 없으면 '게스트')을 쓴다.
     */
    private function resolveNickname(?string $input): string
    {
        if (Auth::check()) {
            return Auth::user()->name;
        }

        $nickname = trim(strip_tags((string) $input));

        return $nickname !== '' ? mb_substr($nickname, 0, 20) : '게스트';
    }
}
