<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Services\User\Auth\Social\GoogleAuthenticator;
use App\Services\User\Auth\Social\KakaoAuthenticator;
use App\Services\User\Auth\Social\SocialAuthenticatorFactory;
use App\Services\User\Auth\SocialAuthException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 소셜 로그인(카카오) — 리다이렉트/콜백 + find-or-create + state(CSRF) 검증.
 * 카카오 API 는 Http::fake 로 목킹한다.
 */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private function configureKakao(): void
    {
        config([
            'services.kakao.client_id' => 'test-rest-key',
            'services.kakao.client_secret' => 'test-secret',
            'services.kakao.redirect' => '/auth/kakao/callback',
            'services.kakao.scope' => 'profile_nickname,account_email',
        ]);
    }

    private function fakeKakaoApi(array $account = []): void
    {
        Http::fake([
            'kauth.kakao.com/oauth/token' => Http::response([
                'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600,
            ]),
            'kapi.kakao.com/v2/user/me' => Http::response(array_merge([
                'id' => 100200300,
                'kakao_account' => [
                    'email' => 'kuser@kakao.com',
                    'profile' => ['nickname' => '토게', 'profile_image_url' => 'http://img/x.jpg'],
                ],
            ], $account)),
        ]);
    }

    public function test_redirect_stores_state_and_sends_to_kakao(): void
    {
        $this->configureKakao();

        $res = $this->get('/auth/kakao/redirect');

        $res->assertStatus(302);
        $this->assertStringContainsString('kauth.kakao.com/oauth/authorize', $res->headers->get('Location'));
        $this->assertNotEmpty(session('social_oauth_state'));
    }

    public function test_callback_creates_user_with_code_identifier_email(): void
    {
        // 이메일 대신 식별 코드(K + 제공자ID + 가입일 yymmdd)로 email 컬럼을 채운다.
        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->configureKakao();
        $this->fakeKakaoApi();

        $res = $this->withSession(['social_oauth_state' => 'abc'])
            ->get('/auth/kakao/callback?code=CODE&state=abc');

        $res->assertRedirect(route('user.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'K100200300_260701', 'name' => '토게']);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'kakao',
            'provider_user_id' => '100200300',
        ]);

        Carbon::setTestNow();
    }

    public function test_callback_reuses_existing_social_account_without_creating_user(): void
    {
        $this->configureKakao();
        $this->fakeKakaoApi();

        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'kakao', 'provider_user_id' => '100200300']);

        $this->withSession(['social_oauth_state' => 'abc'])
            ->get('/auth/kakao/callback?code=CODE&state=abc')
            ->assertRedirect(route('user.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);          // 신규 생성 없음
        $this->assertDatabaseCount('social_accounts', 1); // 중복 연결 없음
    }

    public function test_social_login_does_not_link_by_provider_email(): void
    {
        // 제공자 이메일로 기존 회원과 자동 연결하지 않는다(식별 코드 기반 독립 계정 생성).
        $this->configureKakao();
        $this->fakeKakaoApi(); // kakao_account.email = kuser@kakao.com 를 흘려도 무시돼야 함

        $existing = User::factory()->create(['email' => 'kuser@kakao.com']);

        $this->withSession(['social_oauth_state' => 'abc'])
            ->get('/auth/kakao/callback?code=CODE&state=abc');

        $this->assertDatabaseCount('users', 2);              // 기존 회원과 별개로 신규 생성
        $this->assertAuthenticated();                        // 새로 만든 소셜 계정으로 로그인
        $loggedIn = auth()->user();
        $this->assertNotSame($existing->id, $loggedIn->id);
        $this->assertStringStartsWith('K100200300_', $loggedIn->email);
    }

    public function test_callback_rejects_state_mismatch(): void
    {
        $this->configureKakao();
        $this->fakeKakaoApi();

        $this->withSession(['social_oauth_state' => 'abc'])
            ->get('/auth/kakao/callback?code=CODE&state=WRONG')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent(); // state 불일치 시 카카오 API 조차 호출하지 않음
    }

    public function test_redirect_reports_when_provider_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHas('social_error');
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->get('/auth/naver/redirect')->assertNotFound();
    }

    public function test_social_user_cannot_login_with_password(): void
    {
        // 비밀번호가 없는 소셜 회원은 일반 로그인으로 접근 불가.
        $user = User::factory()->create(['email' => 'social@kakao.com', 'password' => null]);
        $user->socialAccounts()->create(['provider' => 'kakao', 'provider_user_id' => '777']);

        $this->postJson('/login', ['email' => 'social@kakao.com', 'password' => 'anything123!'])
            ->assertStatus(422);
        $this->assertGuest();
    }

    public function test_factory_resolves_providers_and_rejects_unknown(): void
    {
        $factory = app(SocialAuthenticatorFactory::class);

        $this->assertInstanceOf(KakaoAuthenticator::class, $factory->make('kakao'));
        $this->assertInstanceOf(GoogleAuthenticator::class, $factory->make('google'));

        $this->expectException(SocialAuthException::class);
        $factory->make('naver');
    }
}
