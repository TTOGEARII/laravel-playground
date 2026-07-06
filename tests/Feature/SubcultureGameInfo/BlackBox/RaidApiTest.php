<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 블랙박스: 레이드 공개 API(목록/상세/캐릭터)의 입력(쿼리)→출력(JSON) 계약 검증.
 */
class RaidApiTest extends TestCase
{
    use RefreshDatabase;

    private function game(string $slug = 'bluearchive'): Game
    {
        return Game::create([
            'slug' => $slug, 'name' => strtoupper($slug), 'icon' => '🎮', 'sort' => 1, 'active_flg' => true,
        ]);
    }

    private function raid(Game $game, array $o = []): Raid
    {
        return Raid::create(array_merge([
            'subculture_game_id' => $game->id,
            'external_key' => $o['external_key'] ?? 'total-assault-83',
            'name' => '총력전 #83 - 비나',
            'boss_name' => '비나',
            'raid_type' => '총력전',
            'tags' => ['terrain' => '야외', 'armor_type' => '중장갑'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
            'source' => 'mollulog',
        ], $o));
    }

    private function character(Game $game, string $key = '10059', string $name = '미카'): Character
    {
        return Character::create([
            'subculture_game_id' => $game->id, 'external_key' => $key, 'name' => $name,
            'rarity' => '3성', 'source' => 'mollulog', 'active_flg' => true,
        ]);
    }

    public function test_레이드_목록은_게임_상태_필터를_지원한다(): void
    {
        $ba = $this->game('bluearchive');
        $nikke = $this->game('nikke');
        $this->raid($ba);
        $this->raid($nikke, ['external_key' => 'soloraid-5', 'name' => '솔로 레이드 시즌 5',
            'starts_at' => now()->subDays(20), 'ends_at' => now()->subDays(15)]);

        $res = $this->getJson('/api/subculture-game-info/raids')->assertOk();
        $this->assertSame(2, $res->json('meta.total'));

        $res = $this->getJson('/api/subculture-game-info/raids?game=bluearchive')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('bluearchive', $res->json('data.0.game.slug'));

        $res = $this->getJson('/api/subculture-game-info/raids?status=ended')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('ended', $res->json('data.0.status'));
    }

    public function test_레이드_상세는_편성_멤버와_공략글을_포함한다(): void
    {
        $game = $this->game();
        $raid = $this->raid($game);
        $mika = $this->character($game);

        $party = $raid->parties()->create(['title' => '1위 1편성', 'difficulty' => '루나틱', 'sort' => 0, 'source' => 'mollulog']);
        $party->members()->create(['subculture_character_id' => $mika->id, 'slot_type' => 'striker', 'sort' => 0]);
        GuidePost::create([
            'subculture_game_id' => $game->id, 'subculture_raid_id' => $raid->id,
            'source' => 'dc', 'external_id' => '123', 'title' => '비나 루나틱 공략',
            'url' => 'https://gall.dcinside.com/x', 'posted_at' => now(), 'views' => 100,
        ]);

        $res = $this->getJson("/api/subculture-game-info/raids/{$raid->id}")->assertOk();
        $this->assertSame('비나', $res->json('data.boss_name'));
        $this->assertSame('1위 1편성', $res->json('data.parties.0.title'));
        $this->assertSame('미카', $res->json('data.parties.0.members.0.character.name'));
        $this->assertSame('비나 루나틱 공략', $res->json('data.guide_posts.0.title'));
    }

    public function test_캐릭터_목록은_활성만_반환하고_성장도_스키마를_포함한다(): void
    {
        $game = $this->game();
        $this->character($game);
        Character::create([
            'subculture_game_id' => $game->id, 'external_key' => '99999', 'name' => '비활성',
            'source' => 'mollulog', 'active_flg' => false,
        ]);

        $res = $this->getJson('/api/subculture-game-info/characters?game=bluearchive')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('미카', $res->json('data.0.name'));
        // 성장도 스키마는 config 정의를 그대로 노출(폼 렌더링·검증 계약)
        $this->assertSame(
            array_column(config('subculture-game-info.raids.growth_fields.bluearchive'), 'key'),
            array_column($res->json('meta.growth_schema'), 'key'),
        );
    }

    public function test_캐릭터_목록은_game_쿼리가_필수다(): void
    {
        $this->getJson('/api/subculture-game-info/characters')->assertUnprocessable();
    }
}
