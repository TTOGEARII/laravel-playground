<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\CodeRedemption;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 교환 완료 체크 API(로그인 사용자 전용)의 외부 계약 검증.
 */
class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    private function makeCode(): RedeemCode
    {
        $game = Game::create([
            'slug' => 'genshin', 'name' => '원신', 'publisher' => 'HoYoverse',
            'icon' => '⛩️', 'color' => 'accent-teal', 'sort' => 1, 'active_flg' => true,
        ]);

        return RedeemCode::create([
            'subculture_game_id' => $game->id,
            'code' => 'TESTCODE123', 'region' => 'asia', 'source' => 'ennead',
            'source_type' => 'aggregator', 'status' => 'active', 'corroboration_count' => 1,
        ]);
    }

    public function test_guest_is_unauthorized_for_redemption_endpoints(): void
    {
        $code = $this->makeCode();

        $this->getJson('/subculture-game-info/redemptions')->assertUnauthorized();
        $this->postJson('/subculture-game-info/redemptions', ['redeem_code_id' => $code->id])->assertUnauthorized();
        $this->deleteJson("/subculture-game-info/redemptions/{$code->id}")->assertUnauthorized();
    }

    public function test_user_can_mark_and_list_and_unmark(): void
    {
        $user = User::factory()->create();
        $code = $this->makeCode();

        // 표시
        $this->actingAs($user)
            ->postJson('/subculture-game-info/redemptions', ['redeem_code_id' => $code->id])
            ->assertOk()
            ->assertJsonPath('data.redeemed', true);

        $this->assertDatabaseHas('redeem_code_redemptions', [
            'user_id' => $user->id, 'redeem_code_id' => $code->id,
        ]);

        // 목록
        $this->actingAs($user)
            ->getJson('/subculture-game-info/redemptions')
            ->assertOk()
            ->assertJsonPath('data', [$code->id]);

        // 해제
        $this->actingAs($user)
            ->deleteJson("/subculture-game-info/redemptions/{$code->id}")
            ->assertOk()
            ->assertJsonPath('data.redeemed', false);

        $this->assertDatabaseMissing('redeem_code_redemptions', [
            'user_id' => $user->id, 'redeem_code_id' => $code->id,
        ]);
    }

    public function test_mark_is_idempotent(): void
    {
        $user = User::factory()->create();
        $code = $this->makeCode();

        $this->actingAs($user)->postJson('/subculture-game-info/redemptions', ['redeem_code_id' => $code->id])->assertOk();
        $this->actingAs($user)->postJson('/subculture-game-info/redemptions', ['redeem_code_id' => $code->id])->assertOk();

        $this->assertSame(1, CodeRedemption::where('user_id', $user->id)->count());
    }

    public function test_store_validates_existing_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/subculture-game-info/redemptions', ['redeem_code_id' => 999999])
            ->assertStatus(422);
    }

    public function test_redemptions_are_scoped_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $code = $this->makeCode();

        $this->actingAs($alice)->postJson('/subculture-game-info/redemptions', ['redeem_code_id' => $code->id])->assertOk();

        // bob 에게는 보이지 않음
        $this->actingAs($bob)->getJson('/subculture-game-info/redemptions')->assertOk()->assertJsonPath('data', []);
    }

    public function test_page_exposes_login_state_and_redeemed_ids(): void
    {
        $user = User::factory()->create();
        $code = $this->makeCode();
        CodeRedemption::create(['user_id' => $user->id, 'redeem_code_id' => $code->id, 'redeemed_at' => now()]);

        $this->actingAs($user)
            ->get('/subculture-game-info/codes')
            ->assertOk()
            ->assertSee('sgi-redeemed-toggle', false)
            ->assertSee((string) $code->id);
    }
}
