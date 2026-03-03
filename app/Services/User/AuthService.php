<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class AuthService
{
    /**
     * 로그인 시도 (실패 시 로깅, 정보 누수 방지)
     *
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    public function attemptLogin(array $credentials, bool $remember, Request $request): array
    {
        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();
            return ['ok' => true, 'redirect' => route('user.index')];
        }

        $this->logFailedLogin($request->input('email'), $request->ip());

        return [
            'ok' => false,
            'message' => '이메일 또는 비밀번호가 올바르지 않습니다.',
        ];
    }

    /**
     * 로그인 실패 기록 (보안 감사용, 비밀번호는 절대 기록하지 않음)
     */
    protected function logFailedLogin(?string $email, ?string $ip): void
    {
        Log::channel('single')->warning('Login attempt failed', [
            'email' => $email ? substr($email, 0, 3) . '***@***' : null,
            'ip' => $ip,
        ]);
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
        ];
    }
}
