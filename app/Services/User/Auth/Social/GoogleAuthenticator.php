<?php

namespace App\Services\User\Auth\Social;

use App\Services\User\Auth\SocialAuthException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 구글 로그인. 카카오와 동일한 소셜 공통 흐름을 그대로 재사용하고 엔드포인트/필드만 다르다.
 * (GOOGLE_CLIENT_ID 미설정 시 isConfigured()=false → 로그인 비활성)
 */
class GoogleAuthenticator extends AbstractSocialAuthenticator
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const PROFILE_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function provider(): string
    {
        return 'google';
    }

    protected function config(): array
    {
        return config('services.google');
    }

    public function authorizeUrl(string $state): string
    {
        $config = $this->config();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => $config['scope'] ?? 'openid email profile',
        ]);
    }

    protected function fetchProfile(string $code): SocialProfileDto
    {
        $config = $this->config();

        $tokenRes = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if ($tokenRes->failed()) {
            Log::warning('구글 토큰 발급 실패', ['status' => $tokenRes->status(), 'body' => $tokenRes->body()]);
            throw new SocialAuthException('구글 토큰 발급에 실패했습니다.');
        }

        $token = $tokenRes->json();
        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            throw new SocialAuthException('구글 토큰 응답이 올바르지 않습니다.');
        }

        $userRes = Http::withToken($accessToken)->get(self::PROFILE_URL);
        if ($userRes->failed()) {
            Log::warning('구글 사용자 조회 실패', ['status' => $userRes->status(), 'body' => $userRes->body()]);
            throw new SocialAuthException('구글 사용자 조회에 실패했습니다.');
        }

        $user = $userRes->json();
        $expiresIn = (int) ($token['expires_in'] ?? 0);

        return new SocialProfileDto(
            provider: 'google',
            providerUserId: (string) ($user['sub'] ?? ''),
            email: $user['email'] ?? null,
            nickname: $user['name'] ?? null,
            profileImage: $user['picture'] ?? null,
            accessToken: $accessToken,
            refreshToken: $token['refresh_token'] ?? null,
            tokenExpiresAt: $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
        );
    }
}
