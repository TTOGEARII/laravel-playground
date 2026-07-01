<?php

namespace App\Services\User\Auth\Social;

use App\Services\User\Auth\SocialAuthException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 카카오 로그인. (참고: subculture-ground 프로젝트의 kakaoLogin 흐름)
 *  - 인가코드 → https://kauth.kakao.com/oauth/token
 *  - 액세스 토큰 → https://kapi.kakao.com/v2/user/me
 */
class KakaoAuthenticator extends AbstractSocialAuthenticator
{
    private const TOKEN_URL = 'https://kauth.kakao.com/oauth/token';

    private const PROFILE_URL = 'https://kapi.kakao.com/v2/user/me';

    private const AUTHORIZE_URL = 'https://kauth.kakao.com/oauth/authorize';

    public function provider(): string
    {
        return 'kakao';
    }

    protected function config(): array
    {
        return config('services.kakao');
    }

    public function authorizeUrl(string $state): string
    {
        $config = $this->config();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => $config['scope'] ?? 'profile_nickname,profile_image,account_email',
        ]);
    }

    protected function fetchProfile(string $code): SocialProfileDto
    {
        $config = $this->config();

        // 1) 인가코드 → 액세스 토큰 (Client Secret '사용함' 설정 시 함께 전송)
        $tokenRes = Http::asForm()->post(self::TOKEN_URL, array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'] ?: null,
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]));

        if ($tokenRes->failed()) {
            Log::warning('카카오 토큰 발급 실패', ['status' => $tokenRes->status(), 'body' => $tokenRes->body()]);
            throw new SocialAuthException('카카오 토큰 발급에 실패했습니다.');
        }

        $token = $tokenRes->json();
        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            throw new SocialAuthException('카카오 토큰 응답이 올바르지 않습니다.');
        }

        // 2) 액세스 토큰 → 사용자 프로필
        $userRes = Http::withToken($accessToken)->get(self::PROFILE_URL);
        if ($userRes->failed()) {
            Log::warning('카카오 사용자 조회 실패', ['status' => $userRes->status(), 'body' => $userRes->body()]);
            throw new SocialAuthException('카카오 사용자 조회에 실패했습니다.');
        }

        $user = $userRes->json();
        $account = $user['kakao_account'] ?? [];
        $kakaoProfile = $account['profile'] ?? [];
        $expiresIn = (int) ($token['expires_in'] ?? 0);

        return new SocialProfileDto(
            provider: 'kakao',
            providerUserId: (string) ($user['id'] ?? ''),
            email: $account['email'] ?? null,
            nickname: $kakaoProfile['nickname'] ?? ($user['properties']['nickname'] ?? null),
            profileImage: $kakaoProfile['profile_image_url'] ?? ($user['properties']['profile_image'] ?? null),
            accessToken: $accessToken,
            refreshToken: $token['refresh_token'] ?? null,
            tokenExpiresAt: $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
        );
    }
}
