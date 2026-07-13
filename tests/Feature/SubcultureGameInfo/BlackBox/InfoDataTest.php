<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\Banner;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GameEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 블랙박스: 정보검색 대시보드 API(banners/events/schedule)의 계약 검증.
 * 현재(current)·미래시(forecast) scope 분리와 kind 필터를 렌더 없이 JSON 으로만 검증한다.
 */
class InfoDataTest extends TestCase
{
    use RefreshDatabase;

    private function game(): Game
    {
        return Game::create([
            'slug' => 'bluearchive', 'name' => '블루 아카이브', 'publisher' => 'Nexon',
            'icon' => '💙', 'color' => 'accent-indigo', 'sort' => 4, 'active_flg' => true,
        ]);
    }

    public function test_banners_returns_current_scope_with_featured(): void
    {
        $g = $this->game();
        Banner::create([
            'subculture_game_id' => $g->id, 'external_key' => 'gacha-current-1', 'scope' => 'current',
            'kind' => 'character', 'title' => '유즈 픽업', 'featured' => [['external_key' => '10000', 'name' => '아루', 'rarity' => 3, 'image' => 'x']],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'source' => 'schaledb',
        ]);
        Banner::create([
            'subculture_game_id' => $g->id, 'external_key' => 'gacha-forecast-1', 'scope' => 'forecast',
            'kind' => 'character', 'title' => '미래 픽업', 'featured' => [], 'source' => 'schaledb',
        ]);

        $this->getJson('/api/subculture-game-info/banners?game=bluearchive')
            ->assertOk()
            ->assertJsonCount(1, 'data') // current 만
            ->assertJsonPath('data.0.title', '유즈 픽업')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.featured.0.name', '아루');
    }

    public function test_events_defaults_to_event_kind_excluding_raid(): void
    {
        $g = $this->game();
        GameEvent::create(['subculture_game_id' => $g->id, 'external_key' => 'e1', 'scope' => 'current', 'kind' => 'event', 'title' => '여름 이벤트', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'source' => 'schaledb']);
        GameEvent::create(['subculture_game_id' => $g->id, 'external_key' => 'r1', 'scope' => 'current', 'kind' => 'raid', 'title' => '총력전 · 비나', 'starts_at' => now(), 'ends_at' => now()->addDays(2), 'source' => 'schaledb']);

        $this->getJson('/api/subculture-game-info/events?game=bluearchive')
            ->assertOk()
            ->assertJsonCount(1, 'data') // 레이드는 제외
            ->assertJsonPath('data.0.title', '여름 이벤트');
    }

    public function test_schedule_merges_forecast_banners_and_events_sorted(): void
    {
        $g = $this->game();
        GameEvent::create(['subculture_game_id' => $g->id, 'external_key' => 'fe', 'scope' => 'forecast', 'kind' => 'raid', 'title' => '다음 총력전', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(25), 'source' => 'schaledb']);
        Banner::create(['subculture_game_id' => $g->id, 'external_key' => 'fb', 'scope' => 'forecast', 'kind' => 'character', 'title' => '다음 픽업', 'featured' => [], 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(15), 'source' => 'schaledb']);
        // current 는 미래시에서 제외돼야 함
        Banner::create(['subculture_game_id' => $g->id, 'external_key' => 'cb', 'scope' => 'current', 'kind' => 'character', 'title' => '현재 픽업', 'featured' => [], 'source' => 'schaledb']);

        $this->getJson('/api/subculture-game-info/schedule?game=bluearchive')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.row', 'banner') // 더 이른 시작(10일 후) 먼저
            ->assertJsonPath('data.1.row', 'event');
    }
}
