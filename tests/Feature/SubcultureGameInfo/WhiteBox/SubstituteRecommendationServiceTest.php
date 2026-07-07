<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\SubstituteRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: Gemini 대체 추천 — 닫힌 어휘(보유 목록) 강제·캐시·graceful degradation.
 */
class SubstituteRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Raid $raid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-key']);
        $this->game = Game::create(['slug' => 'nikke', 'name' => '승리의 여신: 니케', 'icon' => '🎯', 'sort' => 1, 'active_flg' => true]);
        $this->raid = Raid::create([
            'subculture_game_id' => $this->game->id,
            'external_key' => 'soloraid-6',
            'name' => '솔로 레이드 시즌 5.5',
            'boss_name' => '풍압 보스',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
            'source' => 'letsdoro',
        ]);
    }

    private function character(string $key, string $name, array $traits = []): Character
    {
        return Character::create([
            'subculture_game_id' => $this->game->id, 'external_key' => $key, 'name' => $name,
            'rarity' => 'SSR', 'traits' => $traits ?: null, 'source' => 'letsdoro', 'active_flg' => true,
        ]);
    }

    private function fakeGemini(array $items): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($items, JSON_UNESCAPED_UNICODE)]]]]],
            ]),
        ]);
    }

    public function test_보유_목록에서만_추천하고_목록_밖_이름은_버린다(): void
    {
        $this->character('5155', '나유타');
        $this->character('5124', '신데렐라');
        $this->character('5001', '라피');
        $this->fakeGemini([
            ['name' => '신데렐라', 'reason' => '같은 폭발형 화력 딜러'],
            ['name' => '없는캐릭', 'reason' => '버려야 함'],
            ['name' => '라피', 'reason' => '무난한 대체'],
        ]);

        $result = app(SubstituteRecommendationService::class)
            ->recommend($this->raid, '5155', ['5124', '5001']);

        $this->assertTrue($result['supported']);
        $this->assertSame('나유타', $result['target']['name']);
        $this->assertSame(['신데렐라', '라피'], array_column($result['recommendations'], 'name'));
        $this->assertSame('같은 폭발형 화력 딜러', $result['recommendations'][0]['reason']);
    }

    public function test_같은_질문은_캐시를_써서_gemini_를_한_번만_호출한다(): void
    {
        $this->character('5155', '나유타');
        $this->character('5124', '신데렐라');
        $this->fakeGemini([['name' => '신데렐라']]);

        $service = app(SubstituteRecommendationService::class);
        $service->recommend($this->raid, '5155', ['5124']);
        $service->recommend($this->raid, '5155', ['5124']);

        Http::assertSentCount(1);
    }

    public function test_api_키가_없으면_supported_false(): void
    {
        config(['services.gemini.api_key' => '']);
        Http::fake();

        $result = app(SubstituteRecommendationService::class)->recommend($this->raid, '5155', ['5124']);

        $this->assertFalse($result['supported']);
        $this->assertSame([], $result['recommendations']);
        Http::assertNothingSent();
    }

    public function test_대상_캐릭터가_마스터에_없으면_빈_추천(): void
    {
        $this->character('5124', '신데렐라');
        Http::fake();

        $result = app(SubstituteRecommendationService::class)->recommend($this->raid, '9999', ['5124']);

        $this->assertTrue($result['supported']);
        $this->assertNull($result['target']);
        $this->assertSame([], $result['recommendations']);
        Http::assertNothingSent();
    }

    public function test_gemini_실패는_빈_추천으로_폴백한다(): void
    {
        $this->character('5155', '나유타');
        $this->character('5124', '신데렐라');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 500)]);

        $result = app(SubstituteRecommendationService::class)->recommend($this->raid, '5155', ['5124']);

        $this->assertTrue($result['supported']);
        $this->assertSame([], $result['recommendations']);
    }
}
