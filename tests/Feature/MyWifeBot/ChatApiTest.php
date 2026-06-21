<?php

namespace Tests\Feature\MyWifeBot;

use App\Models\ChatCharacter;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
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

    public function test_logged_in_user_resumes_existing_session_with_history(): void
    {
        config(['services.gemini.api_key' => '']);
        $user = User::factory()->create();
        $character = $this->makeCharacter();

        // 로그인 사용자의 기존 세션 + 대화 기록
        $session = ChatSession::create(['chat_character_id' => $character->id, 'user_id' => $user->id]);
        ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'character', 'content' => '안녕!']);
        ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => '반가워']);

        $response = $this->actingAs($user)->postJson('/api/my-wife-bot/chat/init', [
            'character_id' => $character->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.session_id', (string) $session->id)
            ->assertJsonPath('data.resumed', true)
            ->assertJsonCount(2, 'data.initial_messages');

        // 새 세션을 만들지 않고 기존 것을 재개해야 한다.
        $this->assertDatabaseCount('chat_sessions', 1);
    }

    public function test_guest_never_resumes_and_starts_new_session(): void
    {
        config(['services.gemini.api_key' => '']);
        $character = $this->makeCharacter();

        // 게스트 세션이 있어도 재개하지 않고 새로 시작해야 한다.
        $old = ChatSession::create(['chat_character_id' => $character->id]);

        $this->postJson('/api/my-wife-bot/chat/init', [
            'character_id' => $character->id,
            'session_id' => (string) $old->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.resumed', false);

        $this->assertDatabaseCount('chat_sessions', 2);
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

    public function test_send_persists_messages_and_returns_structured_reply(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"narration": "미아가 손을 흔든다", "message": "반가워요", "affinity": 65}']]],
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
            ->assertJsonPath('data.message.narration', '미아가 손을 흔든다')
            ->assertJsonPath('data.message.role', 'character')
            ->assertJsonPath('data.affinity', 65);

        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => '안녕하세요']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'character', 'content' => '반가워요', 'narration' => '미아가 손을 흔든다']);
        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id, 'affinity' => 65]);
    }

    public function test_suggest_returns_user_reply_suggestions(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"suggestions": ["오늘 뭐 했어?", "기분이 어때?", "같이 산책할래?"]}']]],
                ]],
            ], 200),
        ]);

        $character = $this->makeCharacter();
        $session = ChatSession::create(['chat_character_id' => $character->id]);

        $this->postJson('/api/my-wife-bot/chat/suggest', ['session_id' => (string) $session->id])
            ->assertOk()
            ->assertJsonCount(3, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0', '오늘 뭐 했어?');
    }

    public function test_narrate_generates_and_persists_narration(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"narration": "창밖으로 노을이 진다"}']]],
                ]],
            ], 200),
        ]);

        $character = $this->makeCharacter();
        $session = ChatSession::create(['chat_character_id' => $character->id]);

        $this->postJson('/api/my-wife-bot/chat/narrate', ['session_id' => (string) $session->id])
            ->assertOk()
            ->assertJsonPath('data.narration', '창밖으로 노을이 진다');

        $this->assertDatabaseHas('chat_messages', ['role' => 'character', 'narration' => '창밖으로 노을이 진다']);
    }

    public function test_suggest_returns_404_for_unknown_session(): void
    {
        $this->postJson('/api/my-wife-bot/chat/suggest', ['session_id' => '99999'])
            ->assertStatus(404);
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
