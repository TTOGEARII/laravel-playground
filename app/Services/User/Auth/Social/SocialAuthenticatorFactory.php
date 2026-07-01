<?php

namespace App\Services\User\Auth\Social;

use App\Services\User\Auth\SocialAuthException;

/**
 * provider 문자열('kakao'/'google')로 알맞은 소셜 인증자를 돌려준다.
 * 새 제공자를 붙일 때 여기 한 줄만 추가하면 된다.
 */
class SocialAuthenticatorFactory
{
    public function make(string $provider): AbstractSocialAuthenticator
    {
        return match ($provider) {
            'kakao' => app(KakaoAuthenticator::class),
            'google' => app(GoogleAuthenticator::class),
            default => throw new SocialAuthException('지원하지 않는 로그인 제공자입니다: '.$provider),
        };
    }
}
