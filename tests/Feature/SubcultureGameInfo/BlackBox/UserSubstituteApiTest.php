<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 블랙박스: 내 대체 캐릭터 매핑 API — 세션 인증, 게임별 { character_key: substitute_key } 계약.
 */
class UserSubstituteApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => '토게', 'email' => 'toge@example.com', 'password' => Hash::make('Abcd1234!')]);
        $this->game = Game::create(['slug' => 'nikke', 'name' => '승리의 여신: 니케', 'icon' => '🎯', 'sort' => 1, 'active_flg' => true]);
    }

    public function test_비로그인은_접근할_수_없다(): void
    {
        $this->getJson('/subculture-game-info/my-substitutes?game=nikke')->assertUnauthorized();
    }

    public function test_지정_조회_교체_해제_전체_흐름(): void
    {
        $this->actingAs($this->user);

        // 지정
        $this->putJson('/subculture-game-info/my-substitutes', [
            'game' => 'nikke', 'character_key' => '5155', 'substitute_key' => '5124',
        ])->assertOk()->assertJsonPath('data.substitute_key', '5124');

        // 조회 — 맵 계약
        $this->getJson('/subculture-game-info/my-substitutes?game=nikke')
            ->assertOk()
            ->assertJsonPath('data.5155', '5124');

        // 같은 미보유 캐릭터에 다시 지정하면 교체(upsert, 행 1개 유지)
        $this->putJson('/subculture-game-info/my-substitutes', [
            'game' => 'nikke', 'character_key' => '5155', 'substitute_key' => '5001',
        ])->assertOk();
        $this->assertDatabaseCount('subculture_user_substitutes', 1);
        $this->getJson('/subculture-game-info/my-substitutes?game=nikke')
            ->assertJsonPath('data.5155', '5001');

        // 해제
        $this->deleteJson('/subculture-game-info/my-substitutes', [
            'game' => 'nikke', 'character_key' => '5155',
        ])->assertOk();
        $this->assertDatabaseCount('subculture_user_substitutes', 0);
    }

    public function test_자기_자신을_대체로_지정할_수_없다(): void
    {
        $this->actingAs($this->user);

        $this->putJson('/subculture-game-info/my-substitutes', [
            'game' => 'nikke', 'character_key' => '5155', 'substitute_key' => '5155',
        ])->assertStatus(422)->assertJsonValidationErrors(['substitute_key']);
    }

    public function test_다른_사용자의_매핑은_보이지_않는다(): void
    {
        $other = User::create(['name' => '남', 'email' => 'other@example.com', 'password' => Hash::make('Abcd1234!')]);
        $this->actingAs($other);
        $this->putJson('/subculture-game-info/my-substitutes', [
            'game' => 'nikke', 'character_key' => '5155', 'substitute_key' => '5124',
        ])->assertOk();

        $this->actingAs($this->user);
        $this->getJson('/subculture-game-info/my-substitutes?game=nikke')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_없는_게임은_404(): void
    {
        $this->actingAs($this->user);

        $this->getJson('/subculture-game-info/my-substitutes?game=nope')->assertNotFound();
    }
}
