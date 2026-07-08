<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 블랙박스: 게임 단위 공략글 피드 API(guides 모듈).
 */
class GuidePostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_게임의_최근_공략글을_최신순으로_돌려준다(): void
    {
        $game = Game::create(['slug' => 'trickcal', 'name' => '트릭컬 리바이브', 'icon' => '🎀', 'sort' => 1, 'active_flg' => true]);
        $other = Game::create(['slug' => 'nikke', 'name' => '니케', 'icon' => '🎯', 'sort' => 2, 'active_flg' => true]);
        $raid = Raid::create([
            'subculture_game_id' => $game->id, 'external_key' => 'frontier-18',
            'name' => '프론티어 시즌18', 'raid_type' => '프론티어',
            'starts_at' => now()->subDays(3), 'ends_at' => now()->addDays(3), 'source' => 'manual',
        ]);

        GuidePost::create([
            'subculture_game_id' => $game->id, 'source' => 'arca', 'external_id' => '1',
            'title' => '옛 공략', 'url' => 'https://arca.live/b/trickcal/1', 'posted_at' => now()->subDays(2), 'views' => 10,
        ]);
        GuidePost::create([
            'subculture_game_id' => $game->id, 'source' => 'dc', 'external_id' => '2',
            'title' => '최신 공략', 'url' => 'https://gall.dcinside.com/2', 'posted_at' => now()->subHour(),
            'views' => 99, 'subculture_raid_id' => $raid->id,
        ]);
        GuidePost::create([
            'subculture_game_id' => $other->id, 'source' => 'arca', 'external_id' => '3',
            'title' => '다른 게임 글', 'url' => 'https://arca.live/b/nikketgv/3', 'posted_at' => now(), 'views' => 5,
        ]);

        $res = $this->getJson('/api/subculture-game-info/guide-posts?game=trickcal')->assertOk();

        $this->assertSame(2, $res->json('meta.total')); // 다른 게임 글 제외
        $this->assertSame('최신 공략', $res->json('data.0.title')); // 최신순
        $this->assertSame('프론티어 시즌18', $res->json('data.0.raid_name')); // 레이드 연결 조인
        $this->assertNull($res->json('data.1.raid_name'));
    }

    public function test_game_은_필수고_limit_은_상한이_있다(): void
    {
        Game::create(['slug' => 'trickcal', 'name' => '트릭컬', 'icon' => '🎀', 'sort' => 1, 'active_flg' => true]);

        $this->getJson('/api/subculture-game-info/guide-posts')
            ->assertStatus(422)->assertJsonValidationErrors(['game']);
        $this->getJson('/api/subculture-game-info/guide-posts?game=trickcal&limit=999')
            ->assertStatus(422)->assertJsonValidationErrors(['limit']);
    }
}
