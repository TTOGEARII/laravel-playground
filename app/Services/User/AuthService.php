<?php

namespace App\Services\User;

use App\Models\User;
use App\Services\User\Auth\CredentialAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * 회원 인증 파사드. 일반 로그인은 CredentialAuthenticator(일반 로그인 계층)에 위임하고,
 * 회원가입/로그아웃/검증 규칙 등 나머지 계정 로직을 담당한다.
 * (소셜 로그인은 SocialAuthController + Auth\Social\* 계층에서 처리)
 */
class AuthService
{
    public function __construct(
        private CredentialAuthenticator $credentialAuthenticator,
    ) {}

    /**
     * 일반 로그인 시도 → 일반 로그인 인증자에 위임.
     *
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    public function attemptLogin(array $credentials, bool $remember, Request $request): array
    {
        return $this->credentialAuthenticator->attempt($credentials, $remember, $request);
    }

    /**
     * 회원가입 후 로그인
     */
    public function register(array $data, Request $request): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * 로그아웃
     */
    public function logout(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * 로그인 폼용 검증 규칙 (최소 길이로 빈/짧은 시도 차단)
     *
     * @return array<string, array<int, mixed>>
     */
    public static function loginValidationRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    /**
     * 회원가입용 검증 규칙
     * - 이메일: 형식·중복 검사
     * - 비밀번호: 8자 이상, 영문 대문자 1개 이상, 소문자, 숫자, 특수문자 각 1개 이상
     * - agree: 개인정보 수집·이용 및 이용약관 동의(필수)
     *
     * @return array<string, array<int, mixed>>
     */
    public static function registerValidationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'agree' => ['accepted'],
        ];
    }

    /**
     * 검증 메시지(동의 항목은 문구를 다듬는다).
     *
     * @return array<string, string>
     */
    public static function registerValidationMessages(): array
    {
        return [
            'agree.accepted' => '개인정보 수집·이용 및 이용약관에 동의해 주세요.',
        ];
    }
}
