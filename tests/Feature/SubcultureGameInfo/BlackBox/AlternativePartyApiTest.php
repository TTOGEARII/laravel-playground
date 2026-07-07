<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 블랙박스: 미보유 캐릭터 제외 실전 편성 API 의 입력(exclude/page)→출력(JSON) 계약 검증.
 * POST /api/subculture-game-info/raids/{raid}/alternative-parties
 */
class AlternativePartyApiTest extends TestCase
{
    use RefreshDatabase;

    private function raid(string $slug = 'nikke', array $overrides = []): Raid
    {
        $game = Game::firstOrCreate(
            ['slug' => $slug],
            ['name' => strtoupper($slug), 'icon' => '🎮', 'sort' => 1, 'active_flg' => true],
        );

        return Raid::create(array_merge([
            'subculture_game_id' => $game->id,
            'external_key' => 'soloraid-6',
            'name' => '솔로 레이드 시즌 5.5',
            'raid_type' => '솔로 레이드',
            'starts_at' => '2026-07-01 12:00:00',
            'ends_at' => '2026-07-08 04:59:00',
            'source' => 'letsdoro',
        ], $overrides));
    }

    private function url(Raid $raid): string
    {
        return "/api/subculture-game-info/raids/{$raid->id}/alternative-parties";
    }

    public function test_지원_게임은_원본_랭킹을_걸러_편성을_돌려준다(): void
    {
        Http::fake([
            'api3.letsdoro.com/api/soloraid/seasons' => Http::response([
                ['id' => 6, 'seasonNumber' => 6, 'name' => '시즌 5.5', 'startDate' => '2026-07-01', 'endDate' => '2026-07-08'],
            ]),
            'api3.letsdoro.com/api/soloraid/seasons/6/ranking*' => Http::response(['rankings' => [
                ['rank' => 1, 'totalDamage' => 100, 'squads' => [
                    ['squadNumber' => 1, 'damage' => 100, 'nikkes' => [['nikkeId' => '5155', 'nikkeName' => '나유타']]],
                ]],
                ['rank' => 2, 'totalDamage' => 90, 'squads' => [
                    ['squadNumber' => 1, 'damage' => 90, 'nikkes' => [['nikkeId' => '1007', 'nikkeName' => 'D']]],
                ]],
            ]]),
        ]);

        $res = $this->postJson($this->url($this->raid()), ['exclude' => ['5155']])->assertOk();

        $this->assertTrue($res->json('data.supported'));
        $this->assertSame('letsdoro', $res->json('data.source'));
        $this->assertSame('partial', $res->json('data.mode')); // 깨끗한 랭커 1명 < 3 → 부분 매칭
        $this->assertSame(2, $res->json('data.total_count')); // 전 부대 오염 1위도 포함(대체 지정으로 채울 목표)
        // 깨끗한 2위가 먼저, 오염 1위는 뒤에 is_excluded 표시로
        $this->assertSame('D', $res->json('data.parties.0.members.0.name'));
        $this->assertFalse($res->json('data.parties.0.members.0.is_excluded'));
        $this->assertSame('나유타', $res->json('data.parties.1.members.0.name'));
        $this->assertTrue($res->json('data.parties.1.members.0.is_excluded'));
    }

    public function test_미지원_게임은_supported_false_를_200_으로_돌려준다(): void
    {
        Http::fake();
        $raid = $this->raid('trickcal', ['external_key' => 'frontier-1', 'name' => '프론티어', 'raid_type' => '프론티어', 'source' => 'manual']);

        $this->postJson($this->url($raid), ['exclude' => ['123']])
            ->assertOk()
            ->assertJson(['data' => ['supported' => false]]);
        Http::assertNothingSent();
    }

    public function test_외부_장애에도_500_없이_빈_편성으로_응답한다(): void
    {
        Http::fake(['api3.letsdoro.com/*' => Http::response('', 503)]);

        $res = $this->postJson($this->url($this->raid()), ['exclude' => ['5155']])->assertOk();

        $this->assertTrue($res->json('data.supported'));
        $this->assertSame([], $res->json('data.parties'));
    }

    public function test_exclude_는_문자열_배열만_허용한다(): void
    {
        $raid = $this->raid();

        $this->postJson($this->url($raid), ['exclude' => 'not-array'])->assertUnprocessable();
        $this->postJson($this->url($raid), ['exclude' => [['nested' => 'array']]])->assertUnprocessable();
        $this->postJson($this->url($raid), ['exclude' => [str_repeat('a', 41)]])->assertUnprocessable();
    }

    public function test_exclude_는_500_개를_넘을_수_없다(): void
    {
        $this->postJson($this->url($this->raid()), [
            'exclude' => array_map(fn (int $i) => (string) $i, range(1, 501)),
        ])->assertUnprocessable();
    }

    public function test_page_는_1_이상의_정수만_허용한다(): void
    {
        $raid = $this->raid();

        $this->postJson($this->url($raid), ['exclude' => [], 'page' => 0])->assertUnprocessable();
        $this->postJson($this->url($raid), ['exclude' => [], 'page' => 'abc'])->assertUnprocessable();
    }

    public function test_난이도는_인세인_토먼트_루나틱만_허용한다(): void
    {
        $raid = $this->raid();

        $this->postJson($this->url($raid), ['exclude' => [], 'difficulty' => 'extreme'])->assertUnprocessable();
        $this->postJson($this->url($raid), ['exclude' => [], 'difficulty' => '루나틱'])->assertUnprocessable();
    }

    public function test_출전_통계는_블아_외_게임에서_supported_false_다(): void
    {
        $raid = $this->raid(); // 니케 레이드

        $this->getJson("/api/subculture-game-info/raids/{$raid->id}/student-usage")
            ->assertOk()
            ->assertJsonPath('data.supported', false);
    }

    public function test_없는_레이드는_404(): void
    {
        $this->postJson('/api/subculture-game-info/raids/999999/alternative-parties', ['exclude' => []])
            ->assertNotFound();
    }
}
