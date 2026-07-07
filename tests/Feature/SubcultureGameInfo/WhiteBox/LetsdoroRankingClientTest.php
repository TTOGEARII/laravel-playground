<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\AlternativePartyService;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\LetsdoroRankingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: 레츠도로 실전 편성 클라이언트의 시즌 매핑과
 * 랭커 단위(1차) → 스쿼드 단위(2차) 클라이언트 필터 로직.
 */
class LetsdoroRankingClientTest extends TestCase
{
    use RefreshDatabase;

    private function raid(array $overrides = []): Raid
    {
        $game = Game::firstOrCreate(
            ['slug' => 'nikke'],
            ['name' => '승리의 여신: 니케', 'icon' => '🔫', 'sort' => 2, 'active_flg' => true],
        );

        return Raid::create(array_merge([
            'subculture_game_id' => $game->id,
            'external_key' => 'soloraid-6',
            'name' => '솔로 레이드 시즌 5.5',
            'raid_type' => '솔로 레이드',
            'starts_at' => '2026-07-01 12:00:00',
            'ends_at' => '2026-07-08 04:59:00',
            'source' => 'letsdoro',
            'source_url' => 'https://letsdoro.com/soloraid',
        ], $overrides));
    }

    /** 스쿼드 1개 스텁. $nikkeIds → nikkes 배열. */
    private function squad(int $number, int $damage, array $nikkeIds): array
    {
        return [
            'squadNumber' => $number,
            'damage' => $damage,
            'nikkes' => array_map(fn (string $id) => ['nikkeId' => $id, 'nikkeName' => "니케{$id}"], $nikkeIds),
        ];
    }

    private function ranker(int $rank, array $squads): array
    {
        return ['rank' => $rank, 'totalDamage' => 1000, 'squads' => $squads];
    }

    // ── 필터 로직(순수 함수) ──────────────────────────────────────────

    public function test_1차_필터는_모든_스쿼드가_깨끗한_랭커만_남긴다(): void
    {
        $rankings = [
            $this->ranker(1, [$this->squad(1, 100, ['5155', '5137'])]),          // 제외 니케 사용
            $this->ranker(2, [$this->squad(1, 90, ['1007']), $this->squad(2, 80, ['1010'])]),
            $this->ranker(3, [$this->squad(1, 70, ['1012'])]),
            $this->ranker(4, [$this->squad(1, 60, ['1021'])]),
        ];

        $result = app(LetsdoroRankingClient::class)->filterRankings($rankings, ['5155'], [], 3);

        $this->assertSame('ranker', $result['mode']);
        $this->assertCount(3, $result['groups']); // 2·3·4위 랭커
        $this->assertCount(2, $result['groups'][0]); // 2위 랭커의 스쿼드 2개
        $this->assertSame('2위 1부대', $result['groups'][0][0]['title']);
        $this->assertSame(90, $result['groups'][0][0]['score']);
    }

    public function test_1차_결과가_기준_미만이면_부분_매칭으로_전체_클리어를_보여준다(): void
    {
        $rankings = [
            // 1위: 2부대만 오염 → 랭커 단위 탈락. partial 에선 부대를 빼지 않고 전부 노출
            $this->ranker(1, [$this->squad(1, 100, ['1007']), $this->squad(2, 90, ['5155'])]),
            // 2위: 전부 깨끗 → 유일한 깨끗한 랭커(1명 < 기준 3명)
            $this->ranker(2, [$this->squad(1, 80, ['1010'])]),
        ];

        $result = app(LetsdoroRankingClient::class)->filterRankings($rankings, ['5155'], [], 3);

        $this->assertSame('partial', $result['mode']);
        $this->assertCount(2, $result['groups']);
        // 부대가 군데군데 빠지지 않고 랭커의 전체 클리어가 그대로 나온다
        $this->assertSame(['1위 1부대', '1위 2부대'], array_column($result['groups'][0], 'title'));
        $this->assertSame(['2위 1부대'], array_column($result['groups'][1], 'title'));
        // 오염된 2부대의 미보유 니케에는 is_excluded 표시
        $this->assertFalse($result['groups'][0][0]['members'][0]['is_excluded']);
        $this->assertTrue($result['groups'][0][1]['members'][0]['is_excluded']);
    }

    public function test_부분_매칭은_깨끗한_부대가_많은_랭커를_우선한다(): void
    {
        $rankings = [
            // 1위: 깨끗한 부대 1개
            $this->ranker(1, [$this->squad(1, 100, ['1007']), $this->squad(2, 90, ['5155'])]),
            // 2위: 깨끗한 부대 2개 → 매칭률이 높아 먼저 나와야 한다
            $this->ranker(2, [$this->squad(1, 80, ['1010']), $this->squad(2, 70, ['1012'])]),
            // 3위: 전 부대 오염 → 참고 가치 없음, 제외
            $this->ranker(3, [$this->squad(1, 60, ['5155'])]),
        ];

        $result = app(LetsdoroRankingClient::class)->filterRankings($rankings, ['5155'], [], 3);

        $this->assertSame('partial', $result['mode']);
        $this->assertCount(2, $result['groups']); // 3위는 제외
        $this->assertSame('2위 1부대', $result['groups'][0][0]['title']); // 매칭률 우선
        $this->assertSame('1위 1부대', $result['groups'][1][0]['title']);
    }

    public function test_제외_목록이_비면_전_랭커가_랭커_단위로_통과한다(): void
    {
        $rankings = [
            $this->ranker(1, [$this->squad(1, 100, ['5155'])]),
            $this->ranker(2, [$this->squad(1, 90, ['1007'])]),
            $this->ranker(3, [$this->squad(1, 80, ['1010'])]),
        ];

        $result = app(LetsdoroRankingClient::class)->filterRankings($rankings, [], [], 3);

        $this->assertSame('ranker', $result['mode']);
        $this->assertCount(3, $result['groups']);
    }

    public function test_포함_니케를_모두_가진_랭커만_남긴다(): void
    {
        $rankings = [
            // 1위: 여러 부대에 걸쳐 1007·1010 모두 사용 → 포함 조건 충족
            $this->ranker(1, [$this->squad(1, 100, ['1007', '1099']), $this->squad(2, 90, ['1010'])]),
            // 2위: 1007 만 있고 1010 없음 → 탈락
            $this->ranker(2, [$this->squad(1, 80, ['1007', '1012'])]),
            // 3위: 둘 다 없음 → 탈락
            $this->ranker(3, [$this->squad(1, 70, ['1021'])]),
        ];

        $result = app(LetsdoroRankingClient::class)->filterRankings($rankings, [], ['1007', '1010'], 1);

        $this->assertSame('ranker', $result['mode']);
        $this->assertCount(1, $result['groups']); // 1위 랭커만
        $this->assertSame('1위 1부대', $result['groups'][0][0]['title']);
    }

    // ── 시즌 매핑 + HTTP 흐름 ────────────────────────────────────────

    public function test_시즌을_매핑해_랭킹을_요청하고_캐릭터를_조인한다(): void
    {
        Http::fake([
            'api3.letsdoro.com/api/soloraid/seasons' => Http::response([
                ['id' => 6, 'seasonNumber' => 6, 'name' => '시즌 5.5', 'startDate' => '2026-07-01', 'endDate' => '2026-07-08'],
                ['id' => 5, 'seasonNumber' => 5, 'name' => '시즌 5', 'startDate' => '2026-06-18', 'endDate' => '2026-06-23'],
            ]),
            'api3.letsdoro.com/api/soloraid/seasons/6/ranking*' => Http::response([
                'id' => 122, 'seasonId' => 6, 'server' => 'KR',
                'rankings' => [
                    $this->ranker(1, [$this->squad(1, 100, ['1007'])]),
                    $this->ranker(2, [$this->squad(1, 90, ['1010'])]),
                    $this->ranker(3, [$this->squad(1, 80, ['1012'])]),
                ],
            ]),
        ]);
        $raid = $this->raid();
        Character::create([
            'subculture_game_id' => $raid->subculture_game_id, 'external_key' => '1007',
            'name' => 'D', 'rarity' => 'SSR', 'source' => 'letsdoro', 'active_flg' => true,
        ]);

        $result = app(AlternativePartyService::class)->findParties($raid, ['5155']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/seasons/6/ranking?server=KR'));
        $this->assertTrue($result['supported']);
        $this->assertSame('letsdoro', $result['source']);
        $this->assertSame('ranker', $result['mode']);
        $this->assertSame(3, $result['total_count']);
        $this->assertSame('1위 1부대', $result['parties'][0]['title']);
        $this->assertSame('D', $result['parties'][0]['members'][0]['name']); // 우리 마스터와 조인
        $this->assertSame('니케1010', $result['parties'][1]['members'][0]['name']); // 마스터에 없으면 원본 이름
    }

    public function test_페이지네이션은_랭커_단위로_동작한다(): void
    {
        $rankings = collect(range(1, 8))
            ->map(fn (int $rank) => $this->ranker($rank, [$this->squad(1, 100 - $rank, ['1007'])]))
            ->all();
        Http::fake([
            'api3.letsdoro.com/api/soloraid/seasons' => Http::response([
                ['id' => 6, 'seasonNumber' => 6, 'name' => '시즌 5.5', 'startDate' => '2026-07-01', 'endDate' => '2026-07-08'],
            ]),
            'api3.letsdoro.com/api/soloraid/seasons/6/ranking*' => Http::response(['rankings' => $rankings]),
        ]);

        $result = app(AlternativePartyService::class)->findParties($this->raid(), [], [], 2);

        $this->assertSame(8, $result['total_count']);
        $this->assertCount(3, $result['parties']); // per_page 5 → 2페이지는 6~8위
        $this->assertSame('6위 1부대', $result['parties'][0]['title']);
    }

    public function test_http_실패는_빈_편성으로_폴백한다(): void
    {
        Http::fake(['api3.letsdoro.com/*' => Http::response('', 403)]); // Cloudflare 차단 가정

        $result = app(AlternativePartyService::class)->findParties($this->raid(), ['5155']);

        $this->assertTrue($result['supported']);
        $this->assertSame(0, $result['total_count']);
        $this->assertSame([], $result['parties']);
    }

    public function test_시즌_매핑_실패는_빈_편성으로_폴백한다(): void
    {
        Http::fake([
            'api3.letsdoro.com/api/soloraid/seasons' => Http::response([
                ['id' => 1, 'seasonNumber' => 1, 'name' => '시즌 1', 'startDate' => '2026-02-24', 'endDate' => '2026-03-01'],
            ]),
        ]);
        // 시즌 번호도 기간도 안 맞는 레이드
        $raid = $this->raid([
            'external_key' => 'soloraid-99',
            'starts_at' => '2027-01-01 12:00:00', 'ends_at' => '2027-01-08 04:59:00',
        ]);

        $result = app(AlternativePartyService::class)->findParties($raid, ['5155']);

        $this->assertTrue($result['supported']);
        $this->assertSame([], $result['parties']);
    }
}
