<?php

namespace Tests\Feature\MiniGame;

use App\Models\MiniGame\GameScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 미니게임 점수 랭킹 — 등록(게스트/로그인 닉네임 규칙)·랭킹·외부게임 제외 검증.
 */
class GameScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_submits_score_with_own_nickname(): void
    {
        $res = $this->postJson('/mini-game/scores', [
            'game' => 'tetris',
            'score' => 1500,
            'nickname' => '테트리스왕',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.rank', 1)
            ->assertJsonPath('data.nickname', '테트리스왕')
            ->assertJsonPath('data.rankings.0.nickname', '테트리스왕');

        $this->assertDatabaseHas('game_scores', [
            'game_key' => 'tetris', 'nickname' => '테트리스왕', 'score' => 1500, 'user_id' => null,
        ]);
    }

    public function test_guest_blank_nickname_defaults_to_guest(): void
    {
        $this->postJson('/mini-game/scores', ['game' => 'tetris', 'score' => 10, 'nickname' => '   '])
            ->assertOk()
            ->assertJsonPath('data.nickname', '게스트');
    }

    public function test_logged_in_user_nickname_is_forced(): void
    {
        $user = User::factory()->create(['name' => '진짜회원']);

        $this->actingAs($user)
            ->postJson('/mini-game/scores', ['game' => 'tetris', 'score' => 500, 'nickname' => '위장닉'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '진짜회원'); // 입력값(위장닉) 무시

        $this->assertDatabaseHas('game_scores', [
            'game_key' => 'tetris', 'nickname' => '진짜회원', 'user_id' => $user->id,
        ]);
    }

    public function test_ranking_is_ordered_by_score_desc(): void
    {
        $this->postJson('/mini-game/scores', ['game' => 'tetris', 'score' => 100, 'nickname' => 'A']);
        $this->postJson('/mini-game/scores', ['game' => 'tetris', 'score' => 300, 'nickname' => 'B']);

        $res = $this->postJson('/mini-game/scores', ['game' => 'tetris', 'score' => 200, 'nickname' => 'C']);

        // 200점은 300(B) 다음, 100(A) 앞 → 2위
        $res->assertJsonPath('data.rank', 2)
            ->assertJsonPath('data.rankings.0.nickname', 'B')
            ->assertJsonPath('data.rankings.1.nickname', 'C')
            ->assertJsonPath('data.rankings.2.nickname', 'A');
    }

    public function test_external_game_is_rejected(): void
    {
        // DOOM 은 외부 게임(external) → 랭킹 대상 아님 → 422
        $this->postJson('/mini-game/scores', ['game' => 'doom', 'score' => 999, 'nickname' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('game');

        $this->assertDatabaseCount('game_scores', 0);
    }

    public function test_unknown_game_and_negative_score_rejected(): void
    {
        $this->postJson('/mini-game/scores', ['game' => 'no-such-game', 'score' => 10])
            ->assertJsonValidationErrors('game');

        $this->postJson('/mini-game/scores', ['game' => 'tetris', 'score' => -5])
            ->assertJsonValidationErrors('score');
    }

    public function test_all_rankings_endpoint_excludes_external_game(): void
    {
        GameScore::create(['game_key' => 'tetris', 'nickname' => 'A', 'score' => 10]);
        GameScore::create(['game_key' => 'vampire-survival', 'nickname' => 'B', 'score' => 20]);

        $res = $this->getJson('/mini-game/rankings');

        $res->assertOk();
        $keys = collect($res->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('tetris'));
        $this->assertTrue($keys->contains('vampire-survival'));
        $this->assertFalse($keys->contains('doom')); // 외부 게임 제외
    }
}
