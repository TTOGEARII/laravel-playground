<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 블랙박스: 웹 페이지 GET /subculture-game-info 의 동작 계약.
 * 200 응답 / 코드 노출 / game 필터 / 잘못된 slug 폴백을 렌더된 HTML 로만 검증한다.
 */
class PageTest extends TestCase
{
    use RefreshDatabase;

    private function game(string $slug, array $o = []): Game
    {
        return Game::create([
            'slug' => $slug,
            'name' => $o['name'] ?? strtoupper($slug),
            'icon' => $o['icon'] ?? '🎮',
            'redeem_url_template' => $o['redeem_url_template'] ?? null,
            'redeem_note' => $o['redeem_note'] ?? null,
            'region_default' => $o['region_default'] ?? 'global',
            'sort' => $o['sort'] ?? 1,
            'active_flg' => $o['active_flg'] ?? true,
        ]);
    }

    private function activeCode(Game $game, string $code): RedeemCode
    {
        // status=active 코드는 항상 교차검증(is_verified)으로 메인 노출됨
        return RedeemCode::create([
            'subculture_game_id' => $game->id,
            'code' => $code,
            'region' => CodeRegion::Global->value,
            'rewards' => '원석 60',
            'source' => 'ennead',
            'source_type' => SourceType::Aggregator->value,
            'source_url' => 'https://src.test',
            'seen_sources' => ['ennead', 'seria'],
            'corroboration_count' => 2,
            'status' => CodeStatus::Active->value,
            'found_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'verified_at' => Carbon::now(),
        ]);
    }

    public function test_page_returns_200_and_shows_codes(): void
    {
        $g = $this->game('genshin', ['name' => '원신']);
        $this->activeCode($g, 'GENSHOWN11');

        $res = $this->get('/subculture-game-info');

        $res->assertOk();
        $res->assertSee('GENSHOWN11');
        $res->assertSee('원신');
    }

    public function test_named_route_resolves(): void
    {
        $this->game('genshin', ['name' => '원신']);

        $res = $this->get(route('subculture-game-info.index'));
        $res->assertOk();
    }

    public function test_game_filter_shows_only_selected_game(): void
    {
        $genshin = $this->game('genshin', ['name' => '원신', 'sort' => 1]);
        $starrail = $this->game('starrail', ['name' => '스타레일', 'sort' => 2]);
        $this->activeCode($genshin, 'GENSONLY11');
        $this->activeCode($starrail, 'STARONLY22');

        $res = $this->get('/subculture-game-info?game=genshin');

        $res->assertOk();
        $res->assertSee('GENSONLY11');
        $res->assertDontSee('STARONLY22');
    }

    public function test_invalid_game_slug_falls_back_to_all(): void
    {
        $genshin = $this->game('genshin', ['name' => '원신', 'sort' => 1]);
        $starrail = $this->game('starrail', ['name' => '스타레일', 'sort' => 2]);
        $this->activeCode($genshin, 'GENSALL111');
        $this->activeCode($starrail, 'STARALL222');

        $res = $this->get('/subculture-game-info?game=does-not-exist');

        $res->assertOk();
        // 폴백 시 전체 게임 코드가 모두 노출
        $res->assertSee('GENSALL111');
        $res->assertSee('STARALL222');
    }
}
