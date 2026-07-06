<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\AlternativePartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: 몰루로그 실전 편성 클라이언트의 시즌 매핑(GraphQL) → 제외 요청 형성(ranks POST)
 * → protobuf 파싱 → 캐릭터 조인 흐름과 실패 폴백.
 */
class MollulogRanksClientTest extends TestCase
{
    use RefreshDatabase;

    private function raid(array $overrides = []): Raid
    {
        $game = Game::firstOrCreate(
            ['slug' => 'bluearchive'],
            ['name' => '블루 아카이브', 'icon' => '💙', 'sort' => 1, 'active_flg' => true],
        );

        return Raid::create(array_merge([
            'subculture_game_id' => $game->id,
            'external_key' => 'total-assault-83',
            'name' => '총력전 #83 - 비나',
            'boss_name' => '비나',
            'raid_type' => '총력전',
            'tags' => ['terrain' => '야외', 'armor_type' => '중장갑'],
            'starts_at' => '2026-06-30 00:00:00',
            'ends_at' => '2026-07-07 00:00:00',
            'source' => 'mollulog',
            'source_url' => 'https://mollulog.net/raids/total-assault-83',
        ], $overrides));
    }

    /** baql GraphQL 일정 응답(글로벌 83회차 비나 → 일본 시즌 86, heavy). */
    private function graphqlResponse(): array
    {
        return ['data' => ['raidSchedules' => ['nodes' => [
            [
                'uid' => 'gl_total_assault_82', 'seasonIndex' => 82,
                'startAt' => '2026-05-05T02:00:00Z', 'endAt' => '2026-05-11T19:00:00Z',
                'raidBoss' => ['uid' => 'hovercraft', 'name' => '호버크래프트'],
                'defenseTypeSets' => [['difficulty' => 'lunatic', 'defenseTypes' => ['heavy']]],
                'jpSchedule' => ['seasonIndex' => 85],
            ],
            [
                'uid' => 'gl_total_assault_83', 'seasonIndex' => 83,
                'startAt' => '2026-06-30T02:00:00Z', 'endAt' => '2026-07-06T19:00:00Z',
                'raidBoss' => ['uid' => 'binah', 'name' => '비나'],
                'defenseTypeSets' => [['difficulty' => 'lunatic', 'defenseTypes' => ['heavy']]],
                'jpSchedule' => ['seasonIndex' => 86],
            ],
        ]]]];
    }

    private function ranksBinary(): string
    {
        return file_get_contents(base_path('tests/Fixtures/SubcultureGameInfo/mollulog_ranks.bin'));
    }

    public function test_시즌을_매핑하고_제외_목록을_담아_ranks_를_요청한다(): void
    {
        Http::fake([
            'api.baql.net/*' => Http::response($this->graphqlResponse()),
            'ranks.baql.net/*' => Http::response($this->ranksBinary(), 200, ['Content-Type' => 'application/x-protobuf']),
        ]);
        $raid = $this->raid();
        Character::create([
            'subculture_game_id' => $raid->subculture_game_id, 'external_key' => '10111',
            'name' => '미카', 'rarity' => '3성', 'source' => 'mollulog', 'active_flg' => true,
        ]);

        $result = app(AlternativePartyService::class)->findParties($raid, ['10059', '10059', '20008']);

        // 요청 형성: 일본 시즌 인덱스(86) + 방어타입(중장갑→heavy) + 정렬·중복제거된 excludeStudents
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'ranks.baql.net')) {
                return false;
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $body = json_decode($request->body(), true);

            return $query === ['raidType' => 'total_assault', 'season' => '86', 'defenseType' => 'heavy']
                && $body['perPage'] === config('subculture-game-info.raids.alternative_parties.per_page')
                && $body['page'] === 1
                && $body['includeStudents'] === []
                && $body['excludeStudents'] === [
                    ['uid' => '10059', 'tiers' => []],
                    ['uid' => '20008', 'tiers' => []],
                ];
        });

        // 응답 조립: 서버사이드 필터라 항상 ranker 모드, 웨이브별 파티 펼침 + 캐릭터 조인
        $this->assertTrue($result['supported']);
        $this->assertSame('ranker', $result['mode']);
        $this->assertSame('mollulog', $result['source']);
        $this->assertSame(9859, $result['total_count']);
        $this->assertCount(5, $result['parties']); // 3웨이브 + 1웨이브 + 1웨이브

        $first = $result['parties'][0];
        $this->assertSame('15682위 1편성', $first['title']);
        $this->assertSame(52763770, $first['score']);
        $this->assertSame('10111', $first['members'][0]['external_key']);
        $this->assertSame('미카', $first['members'][0]['name']); // 우리 마스터와 조인
        $this->assertSame(['level' => 90, 'tier' => 5, 'weapon_tier' => 3, 'is_assist' => false], $first['members'][0]['meta']);
        $this->assertNull($result['parties'][0]['members'][1]['name']); // 마스터에 없는 학생은 이름 없음
        $this->assertSame('24366위', $result['parties'][3]['title']); // 단일 웨이브는 편성 번호 생략
    }

    public function test_난이도_지정_시_점수_범위를_담아_요청한다(): void
    {
        Http::fake([
            'api.baql.net/*' => Http::response($this->graphqlResponse()),
            'ranks.baql.net/*' => Http::response($this->ranksBinary(), 200, ['Content-Type' => 'application/x-protobuf']),
        ]);

        // 비나(binah) = 180초 버킷. 인세인 구간 = [기본점+HP점, 토먼트 하한)
        app(AlternativePartyService::class)->findParties($this->raid(), [], 1, 'insane');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'ranks.baql.net')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['score'] ?? null) === [
                'gte' => 6_800_000 + 12_449_600,   // 인세인 하한
                'lt' => 12_200_000 + 18_876_000,   // 토먼트 하한
            ];
        });
    }

    public function test_루나틱은_상한_없이_하한만_담는다(): void
    {
        Http::fake([
            'api.baql.net/*' => Http::response($this->graphqlResponse()),
            'ranks.baql.net/*' => Http::response($this->ranksBinary(), 200, ['Content-Type' => 'application/x-protobuf']),
        ]);

        $result = app(AlternativePartyService::class)->findParties($this->raid(), [], 1, 'lunatic');

        $this->assertSame('lunatic', $result['difficulty']);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'ranks.baql.net')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['score'] ?? null) === ['gte' => 17_710_000 + 25_525_000];
        });
    }

    public function test_외부에는_시즌당_한_번만_요청하고_이후는_캐시를_쓴다(): void
    {
        Http::fake([
            'api.baql.net/*' => Http::response($this->graphqlResponse()),
            'ranks.baql.net/*' => Http::response($this->ranksBinary(), 200, ['Content-Type' => 'application/x-protobuf']),
        ]);
        $raid = $this->raid();
        $service = app(AlternativePartyService::class);

        $service->findParties($raid, ['10059']);
        $service->findParties($raid, ['10059']); // 동일 제외 목록 → 캐시 적중
        $service->findParties($raid, ['10059', '10111']); // 제외 목록이 다르면 새 요청

        Http::assertSentCount(3); // GraphQL 1 + ranks 2
    }

    public function test_시즌_매핑_실패는_빈_편성으로_폴백한다(): void
    {
        Http::fake(['api.baql.net/*' => Http::response($this->graphqlResponse())]);
        // 일정에 없는 회차 + 기간도 안 겹침
        $raid = $this->raid([
            'external_key' => 'total-assault-99',
            'starts_at' => '2027-01-01 00:00:00', 'ends_at' => '2027-01-07 00:00:00',
        ]);

        $result = app(AlternativePartyService::class)->findParties($raid, ['10059']);

        $this->assertTrue($result['supported']);
        $this->assertSame(0, $result['total_count']);
        $this->assertSame([], $result['parties']);
    }

    public function test_ranks_http_실패는_빈_편성으로_폴백한다(): void
    {
        Http::fake([
            'api.baql.net/*' => Http::response($this->graphqlResponse()),
            'ranks.baql.net/*' => Http::response('', 503),
        ]);

        $result = app(AlternativePartyService::class)->findParties($this->raid(), ['10059']);

        $this->assertTrue($result['supported']);
        $this->assertSame([], $result['parties']);
    }

    public function test_protobuf_파싱_실패는_빈_편성으로_폴백한다(): void
    {
        Http::fake([
            'api.baql.net/*' => Http::response($this->graphqlResponse()),
            'ranks.baql.net/*' => Http::response('<html>oops</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = app(AlternativePartyService::class)->findParties($this->raid(), ['10059']);

        $this->assertTrue($result['supported']);
        $this->assertSame([], $result['parties']);
    }

    public function test_지원하지_않는_레이드_종류는_요청_없이_빈_편성으로_폴백한다(): void
    {
        Http::fake();

        $result = app(AlternativePartyService::class)->findParties(
            $this->raid(['raid_type' => '제약해제결전', 'external_key' => 'unrestricted-1']),
            ['10059'],
        );

        $this->assertTrue($result['supported']);
        $this->assertSame([], $result['parties']);
        Http::assertNothingSent();
    }
}
