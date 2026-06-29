<?php

namespace Tests\Feature\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/subculture-game-info/codes 엔드포인트 테스트.
 * game 필터 / community / expired 토글 및 {data, meta} 구조와 redeem_url 생성 검증.
 */
class CodeApiTest extends TestCase
{
    use RefreshDatabase;

    private Game $genshin;

    private Game $bluearchive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->genshin = Game::create([
            'slug' => 'genshin',
            'name' => '원신',
            'redeem_url_template' => 'https://genshin.hoyoverse.com/ko/gift?code={code}',
            'region_default' => 'asia',
            'sort' => 1,
            'active_flg' => true,
        ]);

        $this->bluearchive = Game::create([
            'slug' => 'bluearchive',
            'name' => '블루 아카이브',
            'redeem_url_template' => null,
            'redeem_note' => '게임 내 쿠폰 입력',
            'region_default' => 'kr',
            'sort' => 2,
            'active_flg' => true,
        ]);
    }

    private function makeCode(Game $game, array $overrides = []): RedeemCode
    {
        return RedeemCode::create(array_merge([
            'subculture_game_id' => $game->id,
            'code' => 'GENSHINGIFT',
            'region' => CodeRegion::Global->value,
            'source' => 'ennead',
            'source_type' => SourceType::Aggregator->value,
            'status' => CodeStatus::Active->value,
            'found_at' => now(),
        ], $overrides));
    }

    public function test_returns_data_meta_structure_and_redeem_url(): void
    {
        $this->makeCode($this->genshin, ['code' => 'ABC123']);

        $res = $this->getJson('/api/subculture-game-info/codes')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['game' => ['slug', 'name'], 'code', 'status', 'redeem_url']],
                'meta' => ['total'],
            ])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'ABC123');

        // redeem_url 이 Game 템플릿으로 생성됨
        $this->assertSame(
            'https://genshin.hoyoverse.com/ko/gift?code=ABC123',
            $res->json('data.0.redeem_url')
        );
    }

    public function test_game_filter(): void
    {
        $this->makeCode($this->genshin, ['code' => 'GENCODE1']);
        $this->makeCode($this->bluearchive, ['code' => 'BACODE1']);

        $this->getJson('/api/subculture-game-info/codes?game=genshin')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'GENCODE1');
    }

    public function test_community_zero_returns_main_only(): void
    {
        $this->makeCode($this->genshin, [
            'code' => 'MAINCODE',
            'source_type' => SourceType::Aggregator->value,
        ]);
        $this->makeCode($this->genshin, [
            'code' => 'COMMCODE',
            'source_type' => SourceType::Community->value,
            'status' => CodeStatus::Unverified->value,
        ]);

        // community=0 → 메인만
        $this->getJson('/api/subculture-game-info/codes?community=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'MAINCODE');

        // 기본(community 미지정) → 둘 다
        $this->getJson('/api/subculture-game-info/codes')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_expired_zero_returns_usable_only(): void
    {
        $this->makeCode($this->genshin, [
            'code' => 'GOODCODE',
            'status' => CodeStatus::Active->value,
        ]);
        $this->makeCode($this->genshin, [
            'code' => 'DEADCODE',
            'status' => CodeStatus::Expired->value,
        ]);

        // expired=0(기본) → 사용 가능만
        $this->getJson('/api/subculture-game-info/codes')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'GOODCODE');

        // expired=1 → 만료 포함 전체
        $this->getJson('/api/subculture-game-info/codes?expired=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_null_template_game_has_null_redeem_url(): void
    {
        $this->makeCode($this->bluearchive, ['code' => 'BACODE2']);

        $this->getJson('/api/subculture-game-info/codes?game=bluearchive')
            ->assertOk()
            ->assertJsonPath('data.0.redeem_url', null)
            ->assertJsonPath('data.0.redeem_note', '게임 내 쿠폰 입력');
    }
}
