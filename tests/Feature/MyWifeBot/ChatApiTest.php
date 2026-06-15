<?php

namespace Tests\Feature\MyWifeBot;

use App\Models\ChatCharacter;
use App\Models\ChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCharacter(): ChatCharacter
    {
        return ChatCharacter::create([
            'name' => '미아',
            'short_intro' => '상냥한 비서',
            'genre' => 'romance',
            'target' => 'all',
        ]);
    }

    public function test_init_returns_422_without_character_id(): void
    {
        $this->postJson('/api/my-wife-bot/chat/init', [])
            ->assertStatus(422);
    }

    public function test_init_returns_404_for_unknown_character(): void
    {
        $this->postJson('/api/my-wife-bot/chat/init', ['character_id' => 99999])
            ->assertStatus(404);
    }

    public function test_init_creates_session_and_intro_message(): void
    {
        config(['services.gemini.api_key' => '']); // 폴백 인사말 경로
        $character = $this->makeCharacter();

        $response = $this->postJson('/api/my-wife-bot/chat/init', ['character_id' => $character->id]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['session_id', 'initial_messages']]);

        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertDatabaseHas('chat_messages', ['role' => 'character']);
    }

    public function test_init_still_succeeds_when_gemini_connection_fails(): void
    {
        // API 키는 있지만 연결 실패 → 폴백 인트로로 정상 응답해야 한다 (예외 누수 없음)
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(fn () => throw new ConnectionException('down'));

        $character = $this->makeCharacter();

        $this->postJson('/api/my-wife-bot/chat/init', ['character_id' => $character->id])
            ->assertOk();

        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_send_validates_required_fields(): void
    {
        $this->postJson('/api/my-wife-bot/chat/send', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session_id', 'content']);
    }

    public function test_send_returns_404_for_unknown_session(): void
    {
        $this->postJson('/api/my-wife-bot/chat/send', ['session_id' => '99999', 'content' => '안녕'])
            ->assertStatus(404);
    }

    public function test_send_persists_messages_and_returns_reply(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"message": "반가워요"}']]],
                ]],
            ], 200),
        ]);

        $character = $this->makeCharacter();
        $session = ChatSession::create(['chat_character_id' => $character->id]);

        $response = $this->postJson('/api/my-wife-bot/chat/send', [
            'session_id' => (string) $session->id,
            'content' => '안녕하세요',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.message.text', '반가워요')
            ->assertJsonPath('data.message.role', 'character');

        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => '안녕하세요']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'character', 'content' => '반가워요']);
    }

    public function test_send_degrades_gracefully_on_connection_failure(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(fn () => throw new ConnectionException('down'));

        $character = $this->makeCharacter();
        $session = ChatSession::create(['chat_character_id' => $character->id]);

        // 연결 실패에도 500이 아니라 폴백 응답으로 200이 와야 한다 (Fix 1)
        $this->postJson('/api/my-wife-bot/chat/send', [
            'session_id' => (string) $session->id,
            'content' => '안녕',
        ])->assertOk();

        $this->assertDatabaseHas('chat_messages', ['role' => 'character']);
    }
}
