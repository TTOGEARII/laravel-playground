<?php

namespace App\Http\Middleware;

use App\Support\GuestParticipant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 테트리스 대전을 로그인 없이도 할 수 있게 하는 신원 부여 미들웨어.
 *
 * - 로그인 사용자: 그대로 통과($request->user() = User).
 * - 비로그인 방문자: 세션에 게스트 id/이름을 1회 발급하고 $request->user() 가
 *   그 게스트를 돌려주도록 resolver 를 덮어쓴다.
 *
 * 대전 라우트(방 생성·매칭)와 presence 채널 인증(/broadcasting/auth)에만 적용해
 * 다른 화면의 "비로그인=null" 계약을 건드리지 않는다. Auth::check() 는 그대로 false 라
 *
 * @auth / @guest 등 세션 인증 판정에는 영향이 없다(resolver 만 교체).
 */
class EnsureTetrisIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            $session = $request->session();

            $id = $session->get('tetris_guest_id');
            if (! $id) {
                $id = -random_int(1, 2_000_000_000); // 음수 → 회원 PK와 충돌 없음
                $session->put('tetris_guest_id', $id);
                $session->put('tetris_guest_name', '게스트'.random_int(1000, 9999));
            }
            $name = $session->get('tetris_guest_name', '게스트');

            $request->setUserResolver(fn () => new GuestParticipant((int) $id, (string) $name));
        }

        return $next($request);
    }
}
