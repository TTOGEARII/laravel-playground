<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_logs_in(): void
    {
        // email:rfc,dns 규칙을 통과하도록 실제 MX 레코드가 있는 도메인 사용
        $response = $this->postJson('/register', [
            'name' => '토게',
            'email' => 'toge.test@gmail.com',
            'password' => 'Abcd1234!',
            'password_confirmation' => 'Abcd1234!',
            'agree' => true,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('users', ['email' => 'toge.test@gmail.com']);
        $this->assertAuthenticated();
    }

    public function test_register_requires_privacy_consent(): void
    {
        // 개인정보 수집·이용 동의(agree) 없이 가입 시도 → 422, 가입 안 됨
        $this->postJson('/register', [
            'name' => '토게',
            'email' => 'noconsent@gmail.com',
            'password' => 'Abcd1234!',
            'password_confirmation' => 'Abcd1234!',
        ])->assertStatus(422)->assertJsonValidationErrors(['agree']);

        $this->assertDatabaseMissing('users', ['email' => 'noconsent@gmail.com']);
        $this->assertGuest();
    }

    public function test_register_rejects_weak_password(): void
    {
        $this->postJson('/register', [
            'name' => '토게',
            'email' => 'weak@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        User::create([
            'name' => '토게',
            'email' => 'login@example.com',
            'password' => Hash::make('Abcd1234!'),
        ]);

        $this->postJson('/login', [
            'email' => 'login@example.com',
            'password' => 'Abcd1234!',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password_without_leaking(): void
    {
        User::create([
            'name' => '토게',
            'email' => 'login2@example.com',
            'password' => Hash::make('Abcd1234!'),
        ]);

        $this->postJson('/login', [
            'email' => 'login2@example.com',
            'password' => 'WrongPass1!',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertGuest();
    }
}
