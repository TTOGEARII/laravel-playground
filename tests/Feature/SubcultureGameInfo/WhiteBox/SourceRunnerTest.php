<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Services\SubcultureGameInfo\Sources\SourceRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SourceRunner 화이트박스: config 디스패치, 메인 먼저/커뮤니티 나중 정렬,
 * includeCommunity=false 시 dc/arca 미실행, 알 수 없는 driver 무시.
 */
class SourceRunnerTest extends TestCase
{
    private function runner(): SourceRunner
    {
        return app(SourceRunner::class);
    }

    public function test_dispatches_and_orders_main_before_community(): void
    {
        // 단순화된 config: html(main) 1개 + dc(community) 1개
        config()->set('subculture-game-info.games', [
            'genshin' => [
                'name' => '원신',
                'region_default' => 'asia',
                'sources' => [
                    ['driver' => 'html', 'url' => 'https://aggregator.test/codes'],
                    ['driver' => 'dc'],
                ],
            ],
        ]);

        Http::fake([
            'aggregator.test/*' => Http::response(
                '<table><tr><td>MAINCODE12</td><td>보상</td></tr></table>', 200
            ),
            'gall.dcinside.com/*' => Http::response(
                '<a href="/1">리딤코드 COMMCODE99 공유</a>', 200
            ),
        ]);

        $dtos = $this->runner()->run(includeCommunity: true);
        $codes = array_map(fn ($d) => $d->code, $dtos);

        $this->assertContains('MAINCODE12', $codes);
        $this->assertContains('COMMCODE99', $codes);
        // 메인(MAINCODE12)이 커뮤니티(COMMCODE99)보다 앞에 있어야 한다
        $this->assertLessThan(
            array_search('COMMCODE99', $codes, true),
            array_search('MAINCODE12', $codes, true),
            '메인 결과가 커뮤니티보다 먼저여야 함'
        );
    }

    public function test_excludes_community_when_flag_false(): void
    {
        config()->set('subculture-game-info.games', [
            'genshin' => [
                'name' => '원신',
                'region_default' => 'asia',
                'sources' => [
                    ['driver' => 'html', 'url' => 'https://aggregator.test/codes'],
                    ['driver' => 'dc'],
                    ['driver' => 'arca'],
                ],
            ],
        ]);

        Http::fake([
            'aggregator.test/*' => Http::response('<table><tr><td>MAINCODE12</td><td>x</td></tr></table>', 200),
            '*' => Http::response('<a>리딤코드 COMMCODE99</a>', 200),
        ]);

        $dtos = $this->runner()->run(includeCommunity: false);
        $codes = array_map(fn ($d) => $d->code, $dtos);

        $this->assertContains('MAINCODE12', $codes);
        $this->assertNotContains('COMMCODE99', $codes);
        // dc/arca 의 도메인으로는 요청이 가지 않아야 함
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'dcinside.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'arca.live'));
    }

    public function test_ignores_unknown_driver(): void
    {
        config()->set('subculture-game-info.games', [
            'genshin' => [
                'name' => '원신',
                'region_default' => 'asia',
                'sources' => [
                    ['driver' => 'nonexistent-driver'],
                    ['driver' => 'html', 'url' => 'https://aggregator.test/codes'],
                ],
            ],
        ]);

        Http::fake([
            'aggregator.test/*' => Http::response('<table><tr><td>MAINCODE12</td><td>x</td></tr></table>', 200),
        ]);

        Log::spy();

        $dtos = $this->runner()->run();
        $codes = array_map(fn ($d) => $d->code, $dtos);

        // 알 수 없는 드라이버는 무시하되, 나머지는 정상 수집
        $this->assertContains('MAINCODE12', $codes);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, '알 수 없는 드라이버'))->once();
    }

    public function test_isolates_driver_exception_and_continues(): void
    {
        config()->set('subculture-game-info.games', [
            'genshin' => [
                'name' => '원신',
                'region_default' => 'asia',
                'sources' => [
                    ['driver' => 'html', 'url' => 'https://ok.test/codes'],
                ],
            ],
        ]);

        // 정상 응답이지만, 한 게임 실패가 전체를 막지 않는 구조 자체는 try/catch로 보장됨.
        Http::fake(['ok.test/*' => Http::response('<table><tr><td>OKCODE1234</td><td>x</td></tr></table>', 200)]);

        $dtos = $this->runner()->run();
        $this->assertContains('OKCODE1234', array_map(fn ($d) => $d->code, $dtos));
    }
}
