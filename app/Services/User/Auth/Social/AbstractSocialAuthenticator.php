<?php

namespace App\Services\User\Auth\Social;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\User\Auth\AbstractAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 소셜 로그인(카카오/구글)의 공통 부모.
 *
 * OAuth 흐름은 제공자와 무관하게 동일하다:
 *   (1) 동의 화면으로 보냄 → (2) 돌아온 인가코드로 프로필 조회 → (3) 회원 find-or-create → (4) 세션 로그인.
 * 제공자별로 달라지는 부분(동의 URL 구성, 코드→프로필 교환)만 추상 메서드로 남기고,
 * 나머지 공통 로직(회원 연결·생성, 로그인 마무리)은 여기서 처리한다.
 */
abstract class AbstractSocialAuthenticator extends AbstractAuthenticator
{
    /** 제공자 식별자 (kakao / google). */
    abstract public function provider(): string;

    /** config/services.php 의 해당 제공자 설정. */
    abstract protected function config(): array;

    /** 동의(authorize) 화면 URL 을 만든다. */
    abstract public function authorizeUrl(string $state): string;

    /** 인가코드로 토큰을 교환하고 프로필을 조회해 표준 DTO 로 반환한다. */
    abstract protected function fetchProfile(string $code): SocialProfileDto;

    /** 키가 설정돼 있어야 로그인 버튼을 노출/동작시킨다. */
    public function isConfigured(): bool
    {
        return ! empty($this->config()['client_id']);
    }

    /**
     * 콜백 처리: 인가코드 → 프로필 → 회원 find-or-create → 세션 로그인.
     *
     * @return array{ok: bool, redirect: string}
     */
    public function login(string $code, Request $request): array
    {
        $profile = $this->fetchProfile($code);
        $user = $this->findOrCreateUser($profile);

        return $this->completeLogin($user, $request);
    }

    /**
     * 제공자 콘솔에 등록해야 하는 콜백 절대 URL. 상대경로 설정이면 현재 호스트 기준으로 절대화한다.
     */
    protected function redirectUri(): string
    {
        $redirect = (string) ($this->config()['redirect'] ?? '');

        return Str::startsWith($redirect, ['http://', 'https://']) ? $redirect : url($redirect);
    }

    /**
     * 소셜 프로필로 회원을 찾거나 만든다.
     *   1) 이미 연결된 소셜계정이 있으면 → 그 회원(토큰만 갱신)
     *   2) 없으면 → 신규 회원 생성 후 연결
     *
     * 카카오는 이메일 수집 권한이 없어(구글도 제공 안 할 수 있음) 실제 이메일을 못 받는다.
     * 그래서 email 컬럼에는 제공자 식별 코드를 만들어 넣는다(buildIdentifierEmail).
     */
    protected function findOrCreateUser(SocialProfileDto $profile): User
    {
        $existing = SocialAccount::where('provider', $profile->provider)
            ->where('provider_user_id', $profile->providerUserId)
            ->first();

        if ($existing !== null) {
            $this->syncTokens($existing, $profile);

            return $existing->user;
        }

        // 소셜 회원은 비밀번호가 없다(password 컬럼은 nullable, 여기서 아예 설정하지 않음).
        $user = User::create([
            'name' => $profile->nickname ?: (Str::ucfirst($profile->provider).' 사용자'),
            'email' => $this->buildIdentifierEmail($profile),
        ]);

        $user->socialAccounts()->create([
            'provider' => $profile->provider,
            'provider_user_id' => $profile->providerUserId,
            'nickname' => $profile->nickname,
            'profile_image' => $profile->profileImage,
            'access_token' => $profile->accessToken,
            'refresh_token' => $profile->refreshToken,
            'token_expires_at' => $profile->tokenExpiresAt,
        ]);

        return $user;
    }

    /**
     * 제공자 이메일 대신 email 컬럼에 넣을 고유 식별 코드.
     * 형식: 제공자 접두어(카카오=K, 구글=G) + 제공자 회원ID + '_' + 가입일(yymmdd).
     * 예) 카카오 → "K100200300_260701", 구글 → "G118...\_260701". provider_user_id 가 유일하므로 코드도 유일하다.
     */
    protected function buildIdentifierEmail(SocialProfileDto $profile): string
    {
        return sprintf('%s%s_%s', $this->identifierPrefix(), $profile->providerUserId, now()->format('ymd'));
    }

    /** 제공자별 식별 접두어 (카카오=K, 구글=G). */
    protected function identifierPrefix(): string
    {
        return match ($this->provider()) {
            'kakao' => 'K',
            'google' => 'G',
            default => Str::upper(Str::substr($this->provider(), 0, 1)),
        };
    }

    /** 재로그인 시 제공자 토큰을 최신값으로 갱신(제공자 API 호출용). */
    protected function syncTokens(SocialAccount $account, SocialProfileDto $profile): void
    {
        $account->fill([
            'nickname' => $profile->nickname ?? $account->nickname,
            'profile_image' => $profile->profileImage ?? $account->profile_image,
            'access_token' => $profile->accessToken ?? $account->access_token,
            'refresh_token' => $profile->refreshToken ?? $account->refresh_token,
            'token_expires_at' => $profile->tokenExpiresAt ?? $account->token_expires_at,
        ])->save();
    }
}
