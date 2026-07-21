<?php

namespace App\Http\Controllers\MiniGame;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    /** 대전 페이지(방 생성/입장 + 게임). 로그인 사용자·게스트 모두 가능(EnsureTetrisIdentity 가 신원 부여). */
    public function index(Request $request): View
    {
        $me = $request->user(); // User 또는 GuestParticipant

        return view('mini-game.tetris.versus', [
            'me' => ['id' => (int) $me->getAuthIdentifier(), 'name' => $me->name],
        ]);
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

    /**
     * 빠른 대전 매칭(폴링). 대기자가 있으면 방을 배정하고, 없으면 내가 대기자가 된다.
     * 브로드캐스트/큐 워커 없이 Redis 만으로 처리 — 클라이언트가 2초 간격 폴링하며, 경합은 다음 폴에서 자가치유된다.
     */
    public function matchmake(Request $request): JsonResponse
    {
        $me = (string) $request->user()->id;
        $ttl = now()->addSeconds(30);

        // 1) 이미 나에게 배정된 방이 있으면(대기 중 매칭됨) 그 코드로 입장.
        if ($code = Cache::pull("tetris:mm:room:{$me}")) {
            Cache::forget('tetris:mm:waiting');

            return response()->json(['data' => ['status' => 'matched', 'code' => $code]]);
        }

        // 2) 대기 슬롯을 원자적으로 점유(add 는 없을 때만 성공) → 내가 첫 대기자.
        if (Cache::add('tetris:mm:waiting', $me, $ttl)) {
            return response()->json(['data' => ['status' => 'queued']]);
        }

        // 3) 이미 대기자가 있음. 나 아니면 매칭(방 코드를 상대에게 배정하고 나도 입장).
        $waiting = Cache::get('tetris:mm:waiting');
        if ($waiting !== null && $waiting !== $me) {
            Cache::forget('tetris:mm:waiting');
            $code = $this->generateCode();
            Cache::put("tetris:mm:room:{$waiting}", $code, $ttl); // 상대가 다음 폴에서 받음

            return response()->json(['data' => ['status' => 'matched', 'code' => $code]]);
        }

        // 4) 대기자가 나 자신(이전 폴) → 계속 대기(TTL 갱신).
        Cache::put('tetris:mm:waiting', $me, $ttl);

        return response()->json(['data' => ['status' => 'queued']]);
    }

    /** 빠른 대전 취소 — 대기열/배정에서 나를 제거. */
    public function cancelMatchmake(Request $request): JsonResponse
    {
        $me = (string) $request->user()->id;
        if (Cache::get('tetris:mm:waiting') === $me) {
            Cache::forget('tetris:mm:waiting');
        }
        Cache::forget("tetris:mm:room:{$me}");

        return response()->json(['data' => ['ok' => true]]);
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
