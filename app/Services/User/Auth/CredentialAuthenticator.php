<?php

namespace App\Services\User\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * 일반 로그인 — 이메일/비밀번호 대조 방식.
 *
 * 소셜 가입 사용자는 password 가 null 이므로 Hash::check 가 자연히 실패한다(비밀번호 로그인 불가).
 * 인증에 성공하면 부모의 completeLogin 으로 세션 로그인을 마무리한다.
 */
class CredentialAuthenticator extends AbstractAuthenticator
{
    /**
     * @param  array{email?: string, password?: string}  $credentials
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    public function attempt(array $credentials, bool $remember, Request $request): array
    {
        $user = User::where('email', $credentials['email'] ?? null)->first();

        if ($user === null || $user->password === null || ! Hash::check($credentials['password'] ?? '', $user->password)) {
            $this->logFailedLogin($credentials['email'] ?? null, $request->ip());

            return ['ok' => false, 'message' => '이메일 또는 비밀번호가 올바르지 않습니다.'];
        }

        return $this->completeLogin($user, $request, $remember);
    }

    /**
     * 로그인 실패 기록 (보안 감사용, 비밀번호는 절대 기록하지 않고 이메일도 마스킹).
     */
    protected function logFailedLogin(?string $email, ?string $ip): void
    {
        Log::channel('single')->warning('Login attempt failed', [
            'email' => $email ? substr($email, 0, 3).'***@***' : null,
            'ip' => $ip,
        ]);
    }
}
