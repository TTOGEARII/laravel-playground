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
 * 웹 페이지 GET /subculture-game-info 렌더 테스트.
 */
class IndexPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_page_renders_with_code(): void
    {
        $game = Game::create([
            'slug' => 'genshin',
            'name' => '원신',
            'icon' => '⛩️',
            'redeem_url_template' => 'https://genshin.hoyoverse.com/ko/gift?code={code}',
            'region_default' => 'asia',
            'sort' => 1,
            'active_flg' => true,
        ]);

        RedeemCode::create([
            'subculture_game_id' => $game->id,
            'code' => 'GENSHINGIFT',
            'region' => CodeRegion::Global->value,
            'source' => 'ennead',
            'source_type' => SourceType::Aggregator->value,
            'status' => CodeStatus::Active->value,
            'found_at' => now(),
        ]);

        $this->get('/subculture-game-info')
            ->assertOk()
            ->assertSee('원신')
            ->assertSee('GENSHINGIFT');
    }

    public function test_index_route_name_resolves(): void
    {
        $this->assertSame(
            url('/subculture-game-info'),
            route('subculture-game-info.index')
        );
    }
}
