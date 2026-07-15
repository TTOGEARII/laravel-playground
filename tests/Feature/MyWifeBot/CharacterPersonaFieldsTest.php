<?php

namespace Tests\Feature\MyWifeBot;

use App\Models\MyWifeBot\ChatCharacter;
use App\Models\User;
use App\Services\Gemini\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 페르소나 확장 필드(작품 속 인물 관계·유저 페르소나)의 저장과 프롬프트 반영.
 */
class CharacterPersonaFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'character_name' => '호로',
            'short_intro' => '현랑 호로',
            'genre' => 'romance',
            'target' => 'all',
            'intro_message' => '오랜만이구나.', // 인트로를 채워 Gemini 자동 생성 경로 회피
        ], $overrides);
    }

    public function test_관계와_유저_페르소나를_저장한다(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('my-wife-bot.characters.store'), $this->validPayload([
            'relationships' => "로렌스 — 여행 동료. '멍청한 양치기'라고 부른다.",
            'user_persona' => '유저는 마을에 처음 온 젊은 행상인이다.',
        ]))->assertRedirect();

        $character = ChatCharacter::where('name', '호로')->firstOrFail();
        $this->assertStringContainsString('로렌스', $character->relationships);
        $this->assertStringContainsString('행상인', $character->user_persona);
    }

    public function test_관계_필드는_2000자를_넘으면_거부된다(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('my-wife-bot.characters.store'), $this->validPayload([
                'relationships' => str_repeat('가', 2001),
            ]))
            ->assertSessionHasErrors('relationships');
    }

    public function test_시스템_프롬프트에_관계와_유저_페르소나가_반영된다(): void
    {
        $character = new ChatCharacter([
            'name' => '호로',
            'short_intro' => '현랑 호로',
            'relationships' => '로렌스 — 여행 동료',
            'user_persona' => '유저는 젊은 행상인',
        ]);

        $prompt = PromptBuilder::characterSystem($character);

        $this->assertStringContainsString('[작품 속 인물 관계]', $prompt);
        $this->assertStringContainsString('로렌스 — 여행 동료', $prompt);
        $this->assertStringContainsString('[대화 상대(유저) 설정]', $prompt);
        $this->assertStringContainsString('유저는 젊은 행상인', $prompt);
    }

    public function test_비어_있으면_프롬프트에_해당_섹션이_없다(): void
    {
        $character = new ChatCharacter(['name' => '호로', 'short_intro' => '현랑 호로']);

        $prompt = PromptBuilder::characterSystem($character);

        $this->assertStringNotContainsString('[작품 속 인물 관계]', $prompt);
        $this->assertStringNotContainsString('[대화 상대(유저) 설정]', $prompt);
    }
}
