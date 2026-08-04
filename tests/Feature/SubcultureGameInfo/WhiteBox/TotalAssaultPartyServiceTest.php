<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\MollulogRanksClient;
use App\Services\SubcultureGameInfo\Raids\TotalAssaultPartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 총력전 추천 편성을 baql 랭킹 API(MollulogRanksClient)로 채우는 서비스 검증.
 * 크롤러 /raids 리다이렉트가 놓친 회차를 시즌 단위로 백필한다.
 */
class TotalAssaultPartyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function game(): Game
    {
        return Game::firstOrCreate(
            ['slug' => 'bluearchive'],
            ['name' => '블루아카이브', 'icon' => '🎮', 'sort' => 1, 'active_flg' => true],
        );
    }

    private function raid(Game $game, array $overrides = []): Raid
    {
        return Raid::create(array_merge([
            'subculture_game_id' => $game->id,
            'external_key' => 'total-assault-84',
            'name' => '총력전 #84 - 고즈',
            'boss_name' => '고즈',
            'raid_type' => '총력전',
            'tags' => ['mollulog' => ['armors' => [['type' => '특수장갑', 'difficulty' => '루나틱']], 'season_index' => 84]],
            'starts_at' => '2026-07-28 11:00:00',
            'ends_at' => '2026-08-04 03:00:00',
            'source' => 'mollulog',
            'source_url' => 'https://mollulog.net/raids/total-assault/84',
        ], $overrides));
    }

    private function characters(Game $game, array $keys): void
    {
        foreach ($keys as $key) {
            Character::create([
                'subculture_game_id' => $game->id,
                'external_key' => $key,
                'name' => "학생{$key}",
                'source' => 'mollulog',
                'active_flg' => true,
            ]);
        }
    }

    private function fakeClient(?array $parties): void
    {
        $client = Mockery::mock(MollulogRanksClient::class);
        $client->shouldReceive('findParties')->andReturn($parties === null ? null : [
            'mode' => 'ranker',
            'total_count' => 45316,
            'parties' => $parties,
            'has_more' => true,
            'source_url' => 'https://mollulog.net/raids/total-assault/84',
        ]);
        $this->app->instance(MollulogRanksClient::class, $client);
    }

    private function sampleParties(): array
    {
        $members = fn (array $keys, ?int $assistIdx = null) => collect($keys)
            ->map(fn ($k, $i) => ['external_key' => (string) $k, 'meta' => ['is_assist' => $i === $assistIdx]])
            ->all();

        return [
            ['rank' => 1, 'score' => 45000000, 'title' => '1위', 'members' => $members(['10001', '10002', '10003', '10004', '20001', '20002'], 5)],
            ['rank' => 2, 'score' => 44000000, 'title' => '2위', 'members' => $members(['10001', '10005', '10003', '10004', '20003', '20002'])],
        ];
    }

    public function test_populates_started_total_assault_from_ranks(): void
    {
        $this->travelTo('2026-08-04 06:00:00'); // 고즈 종료 직후
        $game = $this->game();
        $raid = $this->raid($game);
        $this->characters($game, ['10001', '10002', '10003', '10004', '10005', '20001', '20002', '20003']);
        $this->fakeClient($this->sampleParties());

        $count = app(TotalAssaultPartyService::class)->sync($game);

        $this->assertSame(1, $count);
        $raid->refresh()->load('parties.members');
        $this->assertSame(2, $raid->parties->count(), '상위 편성 2개 저장');
        $first = $raid->parties->firstWhere('title', '1위');
        $this->assertSame('mollulog', $first->source);
        $this->assertSame('루나틱', $first->difficulty, 'tags 의 난이도 반영');
        $this->assertSame(6, $first->members->count());
        $this->assertSame('조력자', $first->members[5]->slot_type, 'is_assist → 조력자 slot');
    }

    public function test_skips_future_and_old_and_keeps_manual(): void
    {
        $this->travelTo('2026-08-04 06:00:00');
        $game = $this->game();
        $this->characters($game, ['10001', '10002', '10003', '10004', '20001', '20002']);
        $this->fakeClient($this->sampleParties());

        // 미시작(미래) 회차 — 랭킹 없음 → 스킵
        $future = $this->raid($game, ['external_key' => 'total-assault-90', 'starts_at' => '2026-09-29 11:00:00', 'ends_at' => '2026-10-06 03:00:00']);
        // 백필 창(45일) 밖의 과거 회차 → 스킵
        $old = $this->raid($game, ['external_key' => 'total-assault-70', 'starts_at' => '2026-05-01 11:00:00', 'ends_at' => '2026-05-08 03:00:00']);
        // 이미 편성이 있는 과거 회차(진행 중 아님) → API 재조회 안 함
        $done = $this->raid($game, ['external_key' => 'total-assault-83', 'starts_at' => '2026-07-14 11:00:00', 'ends_at' => '2026-07-21 03:00:00']);
        $done->parties()->create(['title' => '기존', 'source' => 'mollulog', 'sort' => 0]);
        $manual = $done->parties()->create(['title' => '수동편성', 'source' => 'manual', 'sort' => 1]);

        $count = app(TotalAssaultPartyService::class)->sync($game);

        $this->assertSame(0, $count, '미시작·오래된·이미 채워진 회차는 모두 스킵');
        $this->assertSame(0, $future->parties()->count());
        $this->assertSame(0, $old->parties()->count());
        $this->assertSame(2, $done->parties()->count(), '기존 회차 편성 유지');
        $this->assertDatabaseHas('subculture_raid_parties', ['id' => $manual->id, 'source' => 'manual']);
    }

    public function test_refreshes_ongoing_and_preserves_manual(): void
    {
        $this->travelTo('2026-08-01 06:00:00'); // 고즈 진행 중
        $game = $this->game();
        $raid = $this->raid($game);
        $this->characters($game, ['10001', '10002', '10003', '10004', '10005', '20001', '20002', '20003']);
        // 진행 중 회차에 기존 mollulog 편성(오래된) + 수동 편성
        $raid->parties()->create(['title' => '옛 1위', 'source' => 'mollulog', 'sort' => 0]);
        $manual = $raid->parties()->create(['title' => '내 수동', 'source' => 'manual', 'sort' => 9]);
        $this->fakeClient($this->sampleParties());

        app(TotalAssaultPartyService::class)->sync($game);

        $raid->refresh();
        $titles = $raid->parties()->pluck('title')->all();
        $this->assertContains('1위', $titles, '진행 중 회차는 랭킹으로 갱신');
        $this->assertNotContains('옛 1위', $titles, '기존 mollulog 편성은 갈아끼움');
        $this->assertContains('내 수동', $titles, 'manual 편성은 보존');
    }

    public function test_no_ranks_keeps_existing(): void
    {
        $this->travelTo('2026-08-04 06:00:00');
        $game = $this->game();
        $raid = $this->raid($game);
        $this->fakeClient(null); // 랭킹 조회 실패

        $count = app(TotalAssaultPartyService::class)->sync($game);

        $this->assertSame(0, $count);
        $this->assertSame(0, $raid->parties()->count());
    }
}
