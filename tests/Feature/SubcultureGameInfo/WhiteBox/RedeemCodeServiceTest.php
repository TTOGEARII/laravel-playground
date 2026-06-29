<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\SubcultureGameInfo\RedeemCodeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RedeemCodeService::grouped() 화이트박스:
 * API게임(ennead 있음) vs 비-API게임의 메인/보조 분기, 상한 take(30)/take(40).
 */
class RedeemCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private RedeemCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RedeemCodeService;

        // genshin: API 게임(ennead/seria 소스), trickcal: 비-API 게임(html/dc/arca)
        config()->set('subculture-game-info.games', [
            'genshin' => [
                'name' => '원신', 'region_default' => 'asia', 'sort' => 1,
                'sources' => [['driver' => 'ennead'], ['driver' => 'html', 'url' => 'http://x']],
            ],
            'trickcal' => [
                'name' => '트릭컬', 'region_default' => 'kr', 'sort' => 2,
                'sources' => [['driver' => 'html', 'url' => 'http://y'], ['driver' => 'dc']],
            ],
        ]);
    }

    private function game(string $slug): Game
    {
        return Game::create([
            'slug' => $slug, 'name' => $slug, 'region_default' => 'asia', 'sort' => 1, 'active_flg' => true,
        ]);
    }

    private function code(Game $g, array $o = []): RedeemCode
    {
        static $n = 0;
        $n++;

        return RedeemCode::create(array_merge([
            'subculture_game_id' => $g->id,
            'code' => $o['code'] ?? 'CODE'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'region' => 'asia',
            'source' => 'src',
            'source_type' => SourceType::Aggregator->value,
            'status' => CodeStatus::Unverified->value,
            'corroboration_count' => 1,
            'found_at' => now(),
        ], collect($o)->except('code')->all()));
    }

    private function grouped(string $slug): array
    {
        $res = $this->service->grouped($slug);
        $this->assertNotEmpty($res);

        return $res[0];
    }

    // ---------------------------------------------------------------- API 게임 분기
    public function test_api_game_single_source_unverified_not_in_main(): void
    {
        $g = $this->game('genshin');
        // 단일 출처 + 미검증 + 만료일 없음 → 메인(verified)에 안 들어감
        $this->code($g, [
            'status' => CodeStatus::Unverified->value,
            'corroboration_count' => 1,
            'source_type' => SourceType::Aggregator->value,
        ]);

        $g0 = $this->grouped('genshin');
        $this->assertCount(0, $g0['verified']);
        $this->assertCount(1, $g0['unverified']);
    }

    public function test_api_game_active_goes_to_main(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['status' => CodeStatus::Active->value]);

        $g0 = $this->grouped('genshin');
        $this->assertCount(1, $g0['verified']);
        $this->assertCount(0, $g0['unverified']);
    }

    public function test_api_game_corroborated_goes_to_main(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['status' => CodeStatus::Unverified->value, 'corroboration_count' => 2]);

        $this->assertCount(1, $this->grouped('genshin')['verified']);
    }

    public function test_api_game_future_expiry_goes_to_main(): void
    {
        $g = $this->game('genshin');
        // 미검증 단일출처라도 미래 만료일이 있으면 메인
        $this->code($g, [
            'status' => CodeStatus::Unverified->value,
            'corroboration_count' => 1,
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        $this->assertCount(1, $this->grouped('genshin')['verified']);
    }

    // ---------------------------------------------------------------- 비-API 게임 분기
    public function test_non_api_game_aggregator_single_source_in_main(): void
    {
        $g = $this->game('trickcal');
        // 비-API 게임은 aggregator 단일출처 미검증도 메인에 들어감
        $this->code($g, [
            'status' => CodeStatus::Unverified->value,
            'corroboration_count' => 1,
            'source_type' => SourceType::Aggregator->value,
        ]);

        $g0 = $this->grouped('trickcal');
        $this->assertCount(1, $g0['verified']);
        $this->assertCount(0, $g0['unverified']);
    }

    public function test_non_api_game_community_single_source_not_in_main(): void
    {
        $g = $this->game('trickcal');
        // 커뮤니티 단일출처 미검증 → 비-API라도 메인 아님(보조)
        $this->code($g, [
            'status' => CodeStatus::Unverified->value,
            'corroboration_count' => 1,
            'source_type' => SourceType::Community->value,
        ]);

        $g0 = $this->grouped('trickcal');
        $this->assertCount(0, $g0['verified']);
        $this->assertCount(1, $g0['unverified']);
    }

    // ---------------------------------------------------------------- usable 필터(만료 제외)
    public function test_grouped_excludes_expired_codes(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['status' => CodeStatus::Active->value]);
        $this->code($g, ['status' => CodeStatus::Expired->value]);

        $g0 = $this->grouped('genshin');
        $total = $g0['verified']->count() + $g0['unverified']->count();
        $this->assertSame(1, $total, '만료 코드는 usable() 에서 제외');
    }

    // ---------------------------------------------------------------- 상한
    public function test_main_caps_at_30(): void
    {
        $g = $this->game('genshin');
        // 35개 active(메인 대상) 생성
        for ($i = 0; $i < 35; $i++) {
            $this->code($g, ['status' => CodeStatus::Active->value]);
        }

        $this->assertCount(30, $this->grouped('genshin')['verified']);
    }

    public function test_unverified_caps_at_40(): void
    {
        $g = $this->game('genshin');
        // 45개 미검증 단일출처(보조 대상) 생성
        for ($i = 0; $i < 45; $i++) {
            $this->code($g, ['status' => CodeStatus::Unverified->value, 'corroboration_count' => 1]);
        }

        $this->assertCount(40, $this->grouped('genshin')['unverified']);
    }
}
