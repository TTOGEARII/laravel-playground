<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Models\SubcultureGameInfo\RedeemCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 블랙박스: 아티즌 커맨드 subculture:collect 의 동작 계약.
 * 외부 HTTP 는 전부 Http::fake() 로 목킹(실사이트 호출 금지).
 * 검증은 커맨드 실행 결과(DB redeem_codes / 종료코드)만으로 한다.
 */
class CollectCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 매칭 안 된 외부 호출은 빈 응답으로 떨어지게 기본 처리(실사이트 호출 방지)
        Http::preventStrayRequests();
    }

    /**
     * config 를 단일 게임(genshin)로 좁혀 결과를 통제 가능하게 만든다.
     * 실제 게임 메타/드라이버 매핑은 그대로 사용(블랙박스 — 동작 계약만 통제).
     */
    private function useGenshinOnly(array $sources): void
    {
        config()->set('subculture-game-info.games', [
            'genshin' => [
                'name' => '원신',
                'publisher' => 'HoYoverse',
                'icon' => '⛩️',
                'redeem_url_template' => 'https://genshin.hoyoverse.com/ko/gift?code={code}',
                'region_default' => 'asia',
                'sort' => 1,
                'sources' => $sources,
            ],
        ]);
    }

    private function fakeAll(array $overrides = []): void
    {
        Http::fake(array_merge([
            // ennead: { active:[{code,rewards}], inactive:[...] }
            'api.ennead.cc/*' => Http::response([
                'active' => [
                    ['code' => 'GENSHINAAA', 'rewards' => [['name' => 'Primogem', 'count' => 60]]],
                    ['code' => 'GENSHINBBB', 'rewards' => ['Mora 50000']],
                ],
                'inactive' => [
                    ['code' => 'DEADCODE11', 'rewards' => []],
                ],
            ], 200),
            // seria: { codes:[{code,status:OK/NOT_OK,rewards}] }
            'hoyo-codes.seria.moe/*' => Http::response([
                'codes' => [
                    ['code' => 'GENSHINAAA', 'status' => 'OK', 'rewards' => 'Primogem x60'],
                    ['code' => 'NOTOKCODE1', 'status' => 'NOT_OK', 'rewards' => ''],
                ],
            ], 200),
            // 커뮤니티(dc): 코드 키워드가 든 링크 제목
            'gall.dcinside.com/*' => Http::response(
                '<a href="/1">[리딤코드] COMMONLY99 공유합니다</a>', 200
            ),
            // 그 외 모든 외부 호출은 빈 본문(매칭 안 된 호출 fallback)
            '*' => Http::response('', 200),
        ], $overrides));
    }

    // ---------------------------------------------------------------- 기본 수집
    public function test_collect_stores_usable_codes_and_skips_expired(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'ennead'],
            ['driver' => 'seria'],
        ]);
        $this->fakeAll();

        $this->artisan('subculture:collect')->assertExitCode(0);

        // 사용 가능 코드는 저장
        $this->assertDatabaseHas('redeem_codes', ['code' => 'GENSHINAAA']);
        $this->assertDatabaseHas('redeem_codes', ['code' => 'GENSHINBBB']);
        // 만료(inactive/NOT_OK) 코드는 신규 저장 안 됨
        $this->assertDatabaseMissing('redeem_codes', ['code' => 'DEADCODE11']);
        $this->assertDatabaseMissing('redeem_codes', ['code' => 'NOTOKCODE1']);
    }

    // ---------------------------------------------------------------- 교차검증
    public function test_same_code_from_two_sources_increments_corroboration(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'ennead'],
            ['driver' => 'seria'],
        ]);
        $this->fakeAll();

        $this->artisan('subculture:collect')->assertExitCode(0);

        // GENSHINAAA 는 ennead + seria 두 출처에서 관측 → corroboration_count >= 2
        $code = RedeemCode::where('code', 'GENSHINAAA')->first();
        $this->assertNotNull($code);
        $this->assertGreaterThanOrEqual(2, $code->corroboration_count);
        $this->assertContains('ennead', $code->seen_sources);
        $this->assertContains('seria', $code->seen_sources);
    }

    // ---------------------------------------------------------------- 멱등성
    public function test_collect_is_idempotent_on_rerun(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'ennead'],
            ['driver' => 'seria'],
        ]);
        $this->fakeAll();

        $this->artisan('subculture:collect')->assertExitCode(0);
        $countAfterFirst = RedeemCode::count();

        $this->artisan('subculture:collect')->assertExitCode(0);
        $countAfterSecond = RedeemCode::count();

        $this->assertSame($countAfterFirst, $countAfterSecond, '재실행 시 중복 생성이 없어야 함');
        // 같은 코드 한 행만 유지
        $this->assertSame(1, RedeemCode::where('code', 'GENSHINAAA')->count());
    }

    // ---------------------------------------------------------------- 커뮤니티 포함/제외
    public function test_collect_includes_community_codes_by_default(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'ennead'],
            ['driver' => 'dc'],
        ]);
        $this->fakeAll();

        $this->artisan('subculture:collect')->assertExitCode(0);

        $this->assertDatabaseHas('redeem_codes', [
            'code' => 'COMMONLY99',
            'source_type' => 'community',
        ]);
    }

    public function test_no_community_option_excludes_community_codes(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'ennead'],
            ['driver' => 'dc'],
        ]);
        $this->fakeAll();

        $this->artisan('subculture:collect', ['--no-community' => true])->assertExitCode(0);

        // 메인(ennead) 코드는 수집되지만 커뮤니티(dc) 코드는 미수집
        $this->assertDatabaseHas('redeem_codes', ['code' => 'GENSHINAAA']);
        $this->assertDatabaseMissing('redeem_codes', ['code' => 'COMMONLY99']);
        // dc 도메인으로 요청 자체가 나가지 않아야 함
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'dcinside.com'));
    }

    // ---------------------------------------------------------------- HTML 소스
    public function test_collect_parses_html_aggregator_source(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'html', 'url' => 'https://game8.co/games/Genshin-Impact/archives/304759'],
        ]);
        Http::fake([
            'game8.co/*' => Http::response(
                '<table><tr><th>Code</th><th>Rewards</th><th>Expiry</th></tr>'
                .'<tr><td>HTMLCODE12</td><td>원석 60</td><td>2037-12-31</td></tr>'
                .'</table>', 200
            ),
            '*' => Http::response('', 200),
        ]);

        $this->artisan('subculture:collect')->assertExitCode(0);

        $this->assertDatabaseHas('redeem_codes', [
            'code' => 'HTMLCODE12',
            'source_type' => 'aggregator',
        ]);
    }

    // ---------------------------------------------------------------- 종료 코드
    public function test_command_exits_success_with_no_results(): void
    {
        $this->useGenshinOnly([
            ['driver' => 'ennead'],
        ]);
        // ennead 가 빈 active/inactive 반환
        Http::fake([
            'api.ennead.cc/*' => Http::response(['active' => [], 'inactive' => []], 200),
            '*' => Http::response('', 200),
        ]);

        $this->artisan('subculture:collect')->assertExitCode(0);
        $this->assertSame(0, RedeemCode::count());
    }
}
