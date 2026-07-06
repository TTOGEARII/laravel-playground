<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 블랙박스: 내 캐릭터 풀(보유+성장도) 세션 인증 API — CRUD·검증·JSON 내보내기/가져오기 왕복.
 */
class UserCharacterTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'bluearchive', 'name' => '블루 아카이브', 'icon' => '💙', 'sort' => 1, 'active_flg' => true]);
        $this->character = Character::create([
            'subculture_game_id' => $this->game->id, 'external_key' => '10059', 'name' => '미카',
            'rarity' => '3성', 'source' => 'mollulog', 'active_flg' => true,
        ]);
    }

    public function test_비로그인은_접근할_수_없다(): void
    {
        $this->getJson('/subculture-game-info/my-characters')->assertUnauthorized();
        $this->putJson("/subculture-game-info/my-characters/{$this->character->id}", ['owned' => true])->assertUnauthorized();
    }

    public function test_보유와_성장도를_저장하고_조회한다(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson("/subculture-game-info/my-characters/{$this->character->id}", [
                'owned' => true,
                'growth' => ['star' => 5, 'level' => 88],
            ])
            ->assertOk()
            ->assertJsonPath('data.growth.star', 5);

        $res = $this->actingAs($user)->getJson('/subculture-game-info/my-characters?game=bluearchive')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($this->character->id, $res->json('data.0.character_id'));
        $this->assertSame(88, $res->json('data.0.growth.level'));
    }

    public function test_성장도는_게임별_스키마로_검증된다(): void
    {
        $user = User::factory()->create();

        // 범위 밖 값(블아 성급은 1~5)
        $this->actingAs($user)
            ->putJson("/subculture-game-info/my-characters/{$this->character->id}", [
                'owned' => true, 'growth' => ['star' => 9],
            ])
            ->assertUnprocessable();

        // 스키마에 없는 키
        $this->actingAs($user)
            ->putJson("/subculture-game-info/my-characters/{$this->character->id}", [
                'owned' => true, 'growth' => ['hacked' => 1],
            ])
            ->assertUnprocessable();
    }

    public function test_보유_해제하면_기록이_삭제된다(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->putJson("/subculture-game-info/my-characters/{$this->character->id}", ['owned' => true])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson("/subculture-game-info/my-characters/{$this->character->id}")
            ->assertOk();

        $res = $this->actingAs($user)->getJson('/subculture-game-info/my-characters')->assertOk();
        $this->assertSame(0, $res->json('meta.total'));
    }

    public function test_내보낸_jso_n을_그대로_가져오면_동일한_풀이_된다(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->putJson("/subculture-game-info/my-characters/{$this->character->id}", [
                'owned' => true, 'growth' => ['star' => 4, 'weapon_star' => 2],
            ])
            ->assertOk();

        $exported = $this->actingAs($user)
            ->get('/subculture-game-info/my-characters/export?game=bluearchive')
            ->assertOk()
            ->assertHeader('Content-Disposition')
            ->json();

        $this->assertSame(1, $exported['version']);
        $this->assertSame('bluearchive', $exported['game']);
        $this->assertSame('10059', $exported['characters'][0]['external_key']);

        // 풀 초기화 후 그대로 가져오기 → 동일 상태 복원
        $this->actingAs($user)->deleteJson("/subculture-game-info/my-characters/{$this->character->id}")->assertOk();

        $this->actingAs($user)
            ->postJson('/subculture-game-info/my-characters/import', $exported)
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.missing', 0);

        $res = $this->actingAs($user)->getJson('/subculture-game-info/my-characters?game=bluearchive');
        $this->assertSame(['star' => 4, 'weapon_star' => 2], $res->json('data.0.growth'));
    }

    public function test_가져오기는_external_key_실패_시_이름으로_폴백한다(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/subculture-game-info/my-characters/import', [
                'game' => 'bluearchive',
                'characters' => [
                    ['external_key' => 'unknown-key', 'name' => '미카', 'owned' => true, 'growth' => null],
                    ['external_key' => 'no-match', 'name' => '없는캐릭', 'owned' => true, 'growth' => null],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.missing', 1);
    }
}
