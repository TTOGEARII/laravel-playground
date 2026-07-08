<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\AttributeParty;
use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\AttributePartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 화이트박스: 속성별 추천 조합 동기화 — 이름 매칭 폴백·usage 속성 파생·갈아끼움·0건 가드.
 */
class AttributePartyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'trickcal', 'name' => '트릭컬 리바이브', 'icon' => '🎀', 'sort' => 1, 'active_flg' => true]);
    }

    private function character(string $key, string $name, ?string $personality = null): Character
    {
        return Character::create([
            'subculture_game_id' => $this->game->id, 'external_key' => $key, 'name' => $name,
            'rarity' => '3성', 'traits' => $personality ? ['personality' => $personality] : null,
            'source' => 'triplelab', 'active_flg' => true,
        ]);
    }

    public function test_큐레이션_조합을_저장하고_이름_폴백을_흡수한다(): void
    {
        $vela = $this->character('Vela', '벨라', 'Jolly');
        $uros = $this->character('Uros', '우로스', 'Naive');
        $sion = $this->character('Sion', '시온 더 다크불릿', 'Gloomy');

        $stats = app(AttributePartyService::class)->sync($this->game, [
            [
                'kind' => 'curated', 'attribute' => 'Jolly', 'source' => 'team-manager',
                'source_url' => 'https://example.com/builder',
                'members' => [
                    ['name' => '벨라', 'position' => 'front', 'aside' => '모모'],
                    ['name' => '우로스(광기)', 'position' => 'back', 'aside' => null], // 성격 전환형 → 우로스
                    ['name' => '시온', 'position' => 'back', 'aside' => null],         // 접두 → 풀네임 유일 매칭
                    ['name' => '없는사도', 'position' => 'front', 'aside' => null],    // 미매칭 보고
                ],
            ],
        ]);

        $this->assertSame(1, $stats['parties']);
        $this->assertSame(3, $stats['members']);
        $this->assertSame(['없는사도'], $stats['missing']);

        $party = AttributeParty::with('members')->first();
        $this->assertSame('Jolly', $party->attribute);
        $this->assertSame('curated', $party->kind);
        $memberIds = $party->members->pluck('subculture_character_id')->all();
        $this->assertContains($vela->id, $memberIds);
        $this->assertContains($uros->id, $memberIds);
        $this->assertContains($sion->id, $memberIds);
        $this->assertSame(['aside' => '모모'], $party->members->firstWhere('subculture_character_id', $vela->id)->meta);
    }

    public function test_재수집하면_기존_조합을_갈아끼운다(): void
    {
        $this->character('A', '벨라', 'Jolly');
        $service = app(AttributePartyService::class);
        $item = [
            'kind' => 'curated', 'attribute' => 'Jolly', 'source' => 'team-manager',
            'source_url' => null,
            'members' => [['name' => '벨라', 'position' => 'front', 'aside' => null]],
        ];

        $service->sync($this->game, [$item]);
        $service->sync($this->game, [$item]);

        $this->assertSame(1, AttributeParty::count()); // 중복 누적 없음
    }

    public function test_수집_0건이면_기존_데이터를_보존한다(): void
    {
        $this->character('A', '벨라', 'Jolly');
        $service = app(AttributePartyService::class);
        $service->sync($this->game, [[
            'kind' => 'curated', 'attribute' => 'Jolly', 'source' => 'team-manager',
            'source_url' => null,
            'members' => [['name' => '벨라', 'position' => 'front', 'aside' => null]],
        ]]);

        $service->sync($this->game, []); // 크롤 실패로 빈 수집

        $this->assertSame(1, AttributeParty::count());
    }

    public function test_list_는_config_라벨_순서로_그룹핑한다(): void
    {
        $this->character('A', '벨라', 'Jolly');
        $this->character('B', '요미', 'Gloomy');
        $service = app(AttributePartyService::class);
        $service->sync($this->game, [
            ['kind' => 'curated', 'attribute' => 'Jolly', 'source' => 'team-manager', 'source_url' => null,
                'members' => [['name' => '벨라', 'position' => 'front', 'aside' => null]]],
            ['kind' => 'curated', 'attribute' => 'Gloomy', 'source' => 'team-manager', 'source_url' => null,
                'members' => [['name' => '요미', 'position' => 'middle', 'aside' => null]]],
        ]);

        $groups = $service->list($this->game);

        // config 순서: 우울(Gloomy)이 먼저
        $this->assertSame(['우울', '활발', '순수', '냉정', '광기'], $groups->pluck('label')->all());
        $this->assertSame('요미', $groups[0]['parties'][0]['members'][0]['name']);
        $this->assertSame([], $groups[2]['parties']); // 순수는 데이터 없음
    }
}
