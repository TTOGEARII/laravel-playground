<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RedeemCode 모델 화이트박스: usable/verified/main/community scope + accessor.
 */
class RedeemCodeModelTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create([
            'slug' => 'genshin', 'name' => '원신', 'region_default' => 'asia', 'sort' => 1, 'active_flg' => true,
        ]);
    }

    private function code(array $o = []): RedeemCode
    {
        return RedeemCode::create(array_merge([
            'subculture_game_id' => $this->game->id,
            'code' => $o['code'] ?? 'CODE'.fake()->bothify('####??'),
            'region' => 'asia',
            'source' => 'ennead',
            'source_type' => SourceType::Aggregator->value,
            'status' => CodeStatus::Unverified->value,
            'corroboration_count' => 1,
            'found_at' => now(),
        ], collect($o)->except('code')->all()));
    }

    // ---------------------------------------------------------------- usable scope
    public function test_usable_scope_excludes_expired_status_and_past_expiry(): void
    {
        $active = $this->code(['status' => CodeStatus::Active->value]);
        $this->code(['status' => CodeStatus::Expired->value]); // 제외
        $this->code(['status' => CodeStatus::Active->value, 'expires_at' => Carbon::now()->subDay()]); // 제외(과거 만료일)
        $future = $this->code(['status' => CodeStatus::Active->value, 'expires_at' => Carbon::now()->addDay()]);
        $noExpiry = $this->code(['status' => CodeStatus::Unverified->value]);

        $usableIds = RedeemCode::usable()->pluck('id')->all();
        $this->assertContains($active->id, $usableIds);
        $this->assertContains($future->id, $usableIds);
        $this->assertContains($noExpiry->id, $usableIds);
        $this->assertCount(3, $usableIds);
    }

    // ---------------------------------------------------------------- verified scope
    public function test_verified_scope_active_or_corroboration_two(): void
    {
        $active = $this->code(['status' => CodeStatus::Active->value, 'corroboration_count' => 1]);
        $corrob = $this->code(['status' => CodeStatus::Unverified->value, 'corroboration_count' => 2]);
        $single = $this->code(['status' => CodeStatus::Unverified->value, 'corroboration_count' => 1]);

        $ids = RedeemCode::verified()->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertContains($corrob->id, $ids);
        $this->assertNotContains($single->id, $ids);
    }

    // ---------------------------------------------------------------- main/community scope
    public function test_main_and_community_scopes(): void
    {
        $main = $this->code(['source_type' => SourceType::Aggregator->value]);
        $comm = $this->code(['source_type' => SourceType::Community->value]);

        $this->assertSame([$main->id], RedeemCode::main()->pluck('id')->all());
        $this->assertSame([$comm->id], RedeemCode::community()->pluck('id')->all());
    }

    // ---------------------------------------------------------------- isExpired accessor
    public function test_is_expired_accessor(): void
    {
        $this->assertTrue($this->code(['status' => CodeStatus::Expired->value])->is_expired);
        $this->assertTrue($this->code(['status' => CodeStatus::Active->value, 'expires_at' => Carbon::now()->subDay()])->is_expired);
        $this->assertFalse($this->code(['status' => CodeStatus::Active->value, 'expires_at' => Carbon::now()->addDay()])->is_expired);
        $this->assertFalse($this->code(['status' => CodeStatus::Unverified->value])->is_expired);
    }

    // ---------------------------------------------------------------- isVerified accessor
    public function test_is_verified_accessor(): void
    {
        $this->assertTrue($this->code(['status' => CodeStatus::Active->value, 'corroboration_count' => 1])->is_verified);
        $this->assertTrue($this->code(['status' => CodeStatus::Unverified->value, 'corroboration_count' => 2])->is_verified);
        $this->assertFalse($this->code(['status' => CodeStatus::Unverified->value, 'corroboration_count' => 1])->is_verified);
    }

    // ---------------------------------------------------------------- daysLeft accessor
    public function test_days_left_accessor(): void
    {
        $this->assertNull($this->code(['expires_at' => null])->days_left);

        $future = $this->code(['expires_at' => Carbon::now()->addDays(5)]);
        $this->assertSame(5, $future->days_left);

        $past = $this->code(['expires_at' => Carbon::now()->subDays(3)]);
        $this->assertSame(-3, $past->days_left, '만료된 코드는 음수');
    }

    // ---------------------------------------------------------------- Game::redeemUrlFor
    public function test_game_redeem_url_for_template_substitution(): void
    {
        $g = Game::create([
            'slug' => 'x1', 'name' => 'x', 'region_default' => 'asia', 'sort' => 1, 'active_flg' => true,
            'redeem_url_template' => 'https://redeem.test/gift?code={code}',
        ]);
        $this->assertSame('https://redeem.test/gift?code=ABC%20123', $g->redeemUrlFor('ABC 123'));
    }

    public function test_game_redeem_url_for_without_placeholder_returns_template(): void
    {
        $g = Game::create([
            'slug' => 'x2', 'name' => 'x', 'region_default' => 'kr', 'sort' => 1, 'active_flg' => true,
            'redeem_url_template' => 'https://coupon.test/',
        ]);
        $this->assertSame('https://coupon.test/', $g->redeemUrlFor('ANYCODE'));
    }

    public function test_game_redeem_url_for_null_template_returns_null(): void
    {
        $g = Game::create([
            'slug' => 'x3', 'name' => 'x', 'region_default' => 'kr', 'sort' => 1, 'active_flg' => true,
            'redeem_url_template' => null,
        ]);
        $this->assertNull($g->redeemUrlFor('ANYCODE'));
    }
}
