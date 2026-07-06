<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\CharacterSyncService;
use App\Services\SubcultureGameInfo\Raids\RaidSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 화이트박스: 크롤 결과 동기화 서비스의 upsert·편성 재구성·비활성화 가드 동작.
 */
class RaidSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'bluearchive', 'name' => '블루 아카이브', 'icon' => '💙', 'sort' => 1, 'active_flg' => true]);
    }

    private function characterItem(string $key, string $name): array
    {
        return ['external_key' => $key, 'name' => $name, 'rarity' => '3성', 'image_url' => null, 'source_url' => null];
    }

    public function test_캐릭터_동기화는_upsert_하고_미등장을_비활성화한다(): void
    {
        $sync = app(CharacterSyncService::class);

        $stats = $sync->sync($this->game, 'mollulog', [
            $this->characterItem('1', '미카'),
            $this->characterItem('2', '호시노'),
        ]);
        $this->assertSame(2, $stats['created']);

        // 다음 수집에서 호시노가 빠지면(2→1, 가드 비율 0.5 이상) 비활성 처리
        $stats = $sync->sync($this->game, 'mollulog', [$this->characterItem('1', '미카')]);
        $this->assertSame(1, $stats['deactivated']);
        $this->assertFalse(Character::where('external_key', '2')->first()->active_flg);
    }

    public function test_수집량이_급감하면_비활성화를_건너뛴다(): void
    {
        $sync = app(CharacterSyncService::class);
        $sync->sync($this->game, 'mollulog', [
            $this->characterItem('1', '미카'),
            $this->characterItem('2', '호시노'),
            $this->characterItem('3', '아루'),
        ]);

        // 3명 중 1명만 수집(1/3 < 0.5) → 마크업 깨짐 의심, 비활성화 스킵
        $stats = $sync->sync($this->game, 'mollulog', [$this->characterItem('1', '미카')]);
        $this->assertSame(0, $stats['deactivated']);
        $this->assertSame(3, $this->game->characters()->where('active_flg', true)->count());
    }

    public function test_레이드_동기화는_크롤_편성만_갈아끼우고_수동_편성은_보존한다(): void
    {
        Character::create([
            'subculture_game_id' => $this->game->id, 'external_key' => '10059', 'name' => '미카',
            'source' => 'mollulog', 'active_flg' => true,
        ]);
        $sync = app(RaidSyncService::class);

        $item = [
            'external_key' => 'total-assault-83',
            'name' => '총력전 #83 - 비나',
            'boss_name' => '비나',
            'raid_type' => '총력전',
            'tags' => ['terrain' => '야외'],
            'starts_at' => '2026-06-30',
            'ends_at' => '2026-07-07',
            'parties' => [[
                'title' => '1위 1편성', 'sort' => 0,
                'members' => [['external_key' => '10059', 'name' => '미카', 'slot_type' => 'striker', 'sort' => 0]],
            ]],
        ];

        $stats = $sync->sync($this->game, 'mollulog', [$item]);
        $this->assertSame(['raids' => 1, 'parties' => 1, 'members' => 1, 'missing_members' => 0, 'skipped' => 0], $stats);

        // 날짜만 온 종료일은 그날 끝(23:59:59)으로 저장 — 마지막 날 '종료' 오표시 방지
        $this->assertSame(
            '2026-07-07 23:59:59',
            $this->game->raids()->first()->ends_at->format('Y-m-d H:i:s'),
        );

        // 수동 편성 추가 후 재크롤 → 수동은 남고 크롤 편성은 재구성
        $raid = $this->game->raids()->first();
        $raid->parties()->create(['title' => '내가 만든 편성', 'sort' => 99, 'source' => 'manual']);

        $sync->sync($this->game, 'mollulog', [$item]);
        $this->assertSame(2, $raid->parties()->count());
        $this->assertSame(1, $raid->parties()->where('source', 'manual')->count());
    }

    public function test_매칭_안_되는_멤버는_스킵하고_나머지는_저장한다(): void
    {
        $sync = app(RaidSyncService::class);

        $stats = $sync->sync($this->game, 'mollulog', [[
            'external_key' => 'r1', 'name' => '테스트 레이드',
            'parties' => [[
                'title' => 'p1',
                'members' => [['external_key' => 'ghost', 'name' => '없는캐릭', 'slot_type' => null, 'sort' => 0]],
            ]],
        ]]);

        $this->assertSame(1, $stats['raids']);
        $this->assertSame(1, $stats['parties']);
        $this->assertSame(0, $stats['members']);
        $this->assertSame(1, $stats['missing_members']);
    }
}
