<?php

namespace Tests\Feature\MyWifeBot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CharacterAnalyzeTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_requires_source(): void
    {
        $this->postJson(route('my-wife-bot.characters.analyze'), ['source' => '짧음'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
    }

    public function test_analyze_returns_persona_fields_from_source(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"name":"호로","short_intro":"현명한 늑대","personality":"도도하지만 정이 많다","user_alias":"그대","genre":"fantasy","target":"all"}']]],
                ]],
            ], 200),
        ]);

        $this->postJson(route('my-wife-bot.characters.analyze'), [
            'source' => '늑대와 향신료 — 풍요의 신 현랑 호로가 행상인과 여행하는 이야기.',
        ])
            ->assertOk()
            ->assertJsonPath('persona.name', '호로')
            ->assertJsonPath('persona.personality', '도도하지만 정이 많다')
            ->assertJsonPath('persona.genre', 'fantasy');
    }

    public function test_analyze_returns_empty_persona_without_api_key(): void
    {
        config(['services.gemini.api_key' => '']);

        $this->postJson(route('my-wife-bot.characters.analyze'), [
            'source' => '아무 작품 정보나 충분히 길게 입력합니다.',
        ])
            ->assertOk()
            ->assertJsonPath('persona', []);
    }
}
