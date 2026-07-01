<?php

namespace App\Services\User\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 모든 로그인 방식(일반/카카오/구글)의 최상위 부모.
 *
 * 방식마다 "누구인지"를 알아내는 과정은 다르지만(비밀번호 대조 vs OAuth 프로필 조회),
 * 인증이 확정된 뒤 세션에 로그인시키고 결과를 돌려주는 마지막 단계는 완전히 동일하다.
 * 그 공통 지점을 여기 한 곳(completeLogin)에 모아 하위 클래스가 재사용한다.
 */
abstract class AbstractAuthenticator
{
    /**
     * 인증이 확정된 사용자를 세션 로그인 처리한다(세션 고정 공격 방지를 위해 세션 재발급).
     *
     * @return array{ok: bool, redirect: string}
     */
    protected function completeLogin(User $user, Request $request, bool $remember = false): array
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return ['ok' => true, 'redirect' => route('user.index')];
    }
}
