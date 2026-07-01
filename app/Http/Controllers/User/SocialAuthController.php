<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\User\Auth\Social\SocialAuthenticatorFactory;
use App\Services\User\Auth\SocialAuthException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 소셜 로그인(카카오/구글) 리다이렉트/콜백. 실제 인증 로직은 Auth\Social\* 계층이 담당하고,
 * 여기서는 CSRF 방어용 state 검증과 예외 → 로그인 화면 폴백만 책임진다.
 */
class SocialAuthController extends Controller
{
    private const STATE_SESSION_KEY = 'social_oauth_state';

    public function __construct(
        private SocialAuthenticatorFactory $factory,
    ) {}

    /** 제공자 동의 화면으로 보낸다. */
    public function redirect(string $provider, Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('user.index');
        }

        $authenticator = $this->resolve($provider);
        if ($authenticator === null || ! $authenticator->isConfigured()) {
            return redirect()->route('login')
                ->with('social_error', '해당 소셜 로그인이 아직 설정되지 않았습니다.');
        }

        // CSRF 방어: 임의 state 를 세션에 저장하고 콜백에서 대조한다.
        $state = Str::random(40);
        $request->session()->put(self::STATE_SESSION_KEY, $state);

        return redirect()->away($authenticator->authorizeUrl($state));
    }

    /** 제공자가 되돌려준 인가코드로 로그인 처리. */
    public function callback(string $provider, Request $request): RedirectResponse
    {
        if ($request->query('error')) {
            return redirect()->route('login')->with('social_error', '소셜 로그인이 취소되었습니다.');
        }

        // state 대조 (세션에서 pull → 1회용). 불일치 시 CSRF 의심으로 차단.
        $expected = $request->session()->pull(self::STATE_SESSION_KEY);
        $state = (string) $request->query('state', '');
        if ($expected === null || $state === '' || ! hash_equals($expected, $state)) {
            return redirect()->route('login')->with('social_error', '잘못된 접근입니다. 다시 시도해 주세요.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('login')->with('social_error', '인가 코드가 전달되지 않았습니다.');
        }

        $authenticator = $this->resolve($provider);
        if ($authenticator === null) {
            return redirect()->route('login')->with('social_error', '지원하지 않는 로그인입니다.');
        }

        try {
            $result = $authenticator->login($code, $request);
        } catch (SocialAuthException $e) {
            return redirect()->route('login')->with('social_error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('소셜 로그인 처리 오류', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')->with('social_error', '소셜 로그인에 실패했습니다. 잠시 후 다시 시도해 주세요.');
        }

        return redirect()->to($result['redirect'] ?? route('user.index'));
    }

    /** provider 문자열을 인증자로. 미지원이면 null. */
    private function resolve(string $provider): ?\App\Services\User\Auth\Social\AbstractSocialAuthenticator
    {
        try {
            return $this->factory->make($provider);
        } catch (SocialAuthException) {
            return null;
        }
    }
}
