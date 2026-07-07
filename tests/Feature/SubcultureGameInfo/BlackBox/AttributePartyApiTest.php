<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\AttributeParty;
use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 블랙박스: 속성별 추천 조합 API — 게임 게이트·응답 계약.
 */
class AttributePartyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_미지원_게임은_supported_false(): void
    {
        Game::create(['slug' => 'nikke', 'name' => '니케', 'icon' => '🎯', 'sort' => 1, 'active_flg' => true]);

        $this->getJson('/api/subculture-game-info/attribute-parties?game=nikke')
            ->assertOk()
            ->assertJson(['data' => ['supported' => false, 'groups' => []]]);
    }

    public function test_game_파라미터는_필수다(): void
    {
        $this->getJson('/api/subculture-game-info/attribute-parties')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['game']);
    }

    public function test_트릭컬은_속성_그룹과_멤버를_돌려준다(): void
    {
        $game = Game::create(['slug' => 'trickcal', 'name' => '트릭컬 리바이브', 'icon' => '🎀', 'sort' => 1, 'active_flg' => true]);
        $vela = Character::create([
            'subculture_game_id' => $game->id, 'external_key' => 'Vela', 'name' => '벨라',
            'rarity' => '3성', 'traits' => ['personality' => 'Jolly'], 'source' => 'triplelab', 'active_flg' => true,
        ]);
        $party = AttributeParty::create([
            'subculture_game_id' => $game->id, 'attribute' => 'Jolly', 'kind' => 'curated',
            'source' => 'team-manager', 'title' => '추천 편성', 'sort' => 0,
        ]);
        $party->members()->create(['subculture_character_id' => $vela->id, 'position' => 'front', 'sort' => 0]);

        $res = $this->getJson('/api/subculture-game-info/attribute-parties?game=trickcal')->assertOk();

        $this->assertTrue($res->json('data.supported'));
        $groups = collect($res->json('data.groups'));
        $this->assertSame(['우울', '활발', '순수', '냉정', '광기'], $groups->pluck('label')->all());
        $jolly = $groups->firstWhere('attribute', 'Jolly');
        $this->assertSame('추천 편성', $jolly['parties'][0]['title']);
        $this->assertSame('벨라', $jolly['parties'][0]['members'][0]['name']);
        $this->assertSame('front', $jolly['parties'][0]['members'][0]['position']);
    }
}
