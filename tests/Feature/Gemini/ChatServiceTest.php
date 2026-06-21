<?php

namespace Tests\Feature\Gemini;

use App\Models\ChatCharacter;
use App\Services\Gemini\ChatService;
use App\Services\Gemini\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    private function character(): ChatCharacter
    {
        // DB 저장 없이 속성만 사용 (ChatService는 속성만 읽음)
        return new ChatCharacter([
            'name' => '미아',
            'short_intro' => '상냥한 비서',
            'speech_style' => '존댓말',
        ]);
    }

    public function test_chat_returns_fallback_when_api_key_missing(): void
    {
        config(['services.gemini.api_key' => '']);
        $service = $this->app->make(ChatService::class);

        $reply = $service->chat($this->character(), null, [], '안녕');

        $this->assertStringContainsString('미아', $reply['message']);
        $this->assertNull($reply['narration']);
    }

    public function test_chat_degrades_gracefully_on_connection_failure(): void
    {
        // API 키는 있지만 네트워크 연결이 끊긴 상황을 시뮬레이션
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $service = new ChatService($this->app->make(GeminiService::class));

        // 예외가 밖으로 새지 않고 폴백 문자열을 반환해야 한다 (Fix 1)
        $reply = $service->chat($this->character(), null, [], '안녕');

        $this->assertSame('잠시 후 다시 말 걸어 주세요.', $reply['message']);
    }

    public function test_chat_parses_structured_reply_on_success(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"narration": "미아가 미소짓는다", "message": "오늘도 좋은 하루예요", "affinity": 70}']]],
                ]],
            ], 200),
        ]);

        $service = new ChatService($this->app->make(GeminiService::class));

        $reply = $service->chat($this->character(), null, [], '안녕');

        $this->assertSame('오늘도 좋은 하루예요', $reply['message']);
        $this->assertSame('미아가 미소짓는다', $reply['narration']);
        $this->assertSame(70, $reply['affinity']);
    }

    public function test_intro_message_falls_back_to_stored_intro_on_api_failure(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(fn () => throw new ConnectionException('down'));

        $character = $this->character();
        $character->intro_message = '저장된 인트로입니다.';

        $service = new ChatService($this->app->make(GeminiService::class));

        $this->assertSame('저장된 인트로입니다.', $service->getIntroMessage($character));
    }
}
