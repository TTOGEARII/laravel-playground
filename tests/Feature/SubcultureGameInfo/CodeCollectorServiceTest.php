<?php

namespace Tests\Feature\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\SubcultureGameInfo\CodeCollectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 수집 오케스트레이터 통합 테스트.
 * ennead(JSON) · aggregator(HTML) · dc(community) 를 Http::fake 로 목킹하고,
 * collect() 후 게임 ensure + 코드 적재 + includeCommunity 토글 동작을 검증한다.
 */
class CodeCollectorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeHttp(): void
    {
        // config 를 좁혀 테스트 대상 URL 만 두고 fake 와 1:1 매칭한다.
        config([
            'subculture-game-info.sources.aggregators' => [
                'wuthering' => ['https://wuthering.gg/codes'],
            ],
            'subculture-game-info.sources.community.dc.enabled' => true,
            'subculture-game-info.sources.community.dc.galleries' => [
                'genshin' => 'onshinproject',
            ],
        ]);

        Http::fake([
            'api.ennead.cc/*' => Http::response([
                'active' => [['code' => 'GENSHINGIFT', 'rewards' => ['Primogem x60']]],
                'inactive' => [],
            ]),
            'wuthering.gg/*' => Http::response(
                '<html><body><td>WUTHERINGNEW1</td><b>ABCD1234</b></body></html>'
            ),
            'gall.dcinside.com/*' => Http::response(
                '<html><body><a href="/view/1">원신 리딤코드 GENSHINDC01 풀렸어요</a></body></html>'
            ),
        ]);
    }

    public function test_collect_ensures_games_and_stores_codes(): void
    {
        $this->fakeHttp();

        $stats = app(CodeCollectorService::class)->collect(includeCommunity: true);

        // config 의 6개 게임이 ensure 됨
        $this->assertSame(6, Game::count());

        // 각 소스의 코드가 적재됨
        $this->assertDatabaseHas('redeem_codes', ['code' => 'GENSHINGIFT']);
        $this->assertDatabaseHas('redeem_codes', ['code' => 'WUTHERINGNEW1']);
        $this->assertDatabaseHas('redeem_codes', ['code' => 'GENSHINDC01']);

        // 커뮤니티 소스 코드는 source_type=community
        $dc = RedeemCode::where('code', 'GENSHINDC01')->first();
        $this->assertSame(SourceType::Community, $dc->source_type);

        $this->assertGreaterThanOrEqual(3, $stats['collected']);
        $this->assertGreaterThanOrEqual(3, $stats['created']);
    }

    public function test_collect_without_community_skips_dc_source(): void
    {
        $this->fakeHttp();

        app(CodeCollectorService::class)->collect(includeCommunity: false);

        $this->assertDatabaseHas('redeem_codes', ['code' => 'GENSHINGIFT']);
        $this->assertDatabaseHas('redeem_codes', ['code' => 'WUTHERINGNEW1']);
        // 커뮤니티 소스는 실행되지 않아 DC 코드가 없어야 한다
        $this->assertDatabaseMissing('redeem_codes', ['code' => 'GENSHINDC01']);
        $this->assertSame(0, RedeemCode::community()->count());
    }
}
