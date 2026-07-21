<?php

namespace App\Http\Controllers\MiniGame;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 테트리스 실시간 대전(Reverb WebSocket).
 *
 * 방/입장은 presence 채널(tetris-room.{code})로 다룬다 — 멤버 목록이 곧 참가자·관전자다.
 * 대전 중 가비지·보드 스냅샷은 프론트에서 client event(whisper)로 피어 간 직접 중계한다.
 * 여기(서버)는 방 코드 발급과 페이지 렌더만 담당해 얇게 유지한다.
 *
 * 멀티플레이는 presence 인증을 단순화하려 로그인 사용자 전용(싱글은 게스트 허용 유지).
 */
class TetrisVersusController extends Controller
{
    /** 대전 페이지(방 생성/입장 + 게임). 로그인 필요. */
    public function index(): View
    {
        return view('mini-game.tetris.versus');
    }

    /**
     * 새 방 코드 발급. 코드만 만들어 반환하고(공유 링크 ?room=CODE), 방 상태는 presence 채널이 관리한다.
     * 코드는 사람이 공유하기 쉬운 대문자+숫자 6자리(혼동 문자 제외).
     */
    public function createRoom(Request $request): JsonResponse
    {
        $code = $this->generateCode();

        return response()->json(['data' => ['code' => $code]]);
    }

    /** 혼동되는 글자(0/O/1/I/L) 제외한 6자리 방 코드. */
    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        return collect(range(1, 6))
            ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->implode('');
    }
}
