<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Services\SubcultureGameInfo\Raids\CrawlerScriptRunner;
use App\Services\SubcultureGameInfo\Raids\GuideBodyFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 화이트박스: 공략글 본문 수집기의 셀렉터 파싱·브라우저 렌더 폴백·방어적 실패 처리.
 * 브라우저(Playwright 사이드카)는 Mockery 로 대체해 실제 프로세스를 띄우지 않는다.
 */
class GuideBodyFetcherTest extends TestCase
{
    use RefreshDatabase;

    /** 브라우저 폴백이 주어진 HTML(또는 null)을 반환하도록 대체. */
    private function fakeBrowser(?string $html): void
    {
        $mock = Mockery::mock(CrawlerScriptRunner::class);
        $mock->shouldReceive('fetchHtml')->andReturn($html);
        $this->app->instance(CrawlerScriptRunner::class, $mock);
    }

    private function guidePost(string $source = 'dc', string $url = 'https://gall.dcinside.com/mgallery/board/view/?id=x&no=1'): GuidePost
    {
        $game = Game::firstOrCreate(
            ['slug' => 'bluearchive'],
            ['name' => '블루 아카이브', 'icon' => '💙', 'sort' => 1, 'active_flg' => true],
        );

        return GuidePost::create([
            'subculture_game_id' => $game->id, 'source' => $source, 'external_id' => '1',
            'title' => '비나 공략', 'url' => $url, 'views' => 0,
        ]);
    }

    public function test_설정된_셀렉터로_충분한_본문이면_htt_p_결과를_쓴다(): void
    {
        $this->fakeBrowser(null); // 브라우저는 부르지 않아야 하지만 안전하게 null
        $long = str_repeat('미카 없으면 사키로 대체 가능하고 히마리 편성도 추천된다. ', 6); // 120자 이상
        Http::fake([
            'gall.dcinside.com/*' => Http::response('<html><body><div class="write_div"><p>'.$long.'</p><script>track()</script></div></body></html>'),
        ]);

        $text = app(GuideBodyFetcher::class)->fetch($this->guidePost());

        $this->assertStringContainsString('미카 없으면 사키로 대체', $text);
    }

    public function test_htt_p_본문이_짧으면_브라우저_렌더로_재시도한다(): void
    {
        // 평문 HTTP 는 축약 페이지(짧음) → 브라우저 렌더가 더 긴 본문을 준다
        Http::fake(['arca.live/*' => Http::response('<html><body><div class="article-content">짧음</div></body></html>')]);
        $rendered = '<html><body><div class="article-content">'.str_repeat('티페레트 99층 오토 편성 히후미 이즈나 사오리 히마리 아루 하나코. ', 5).'</div></body></html>';
        $this->fakeBrowser($rendered);

        $text = app(GuideBodyFetcher::class)->fetch($this->guidePost('arca', 'https://arca.live/b/bluearchive/1'));

        $this->assertStringContainsString('티페레트 99층 오토', $text);
        $this->assertGreaterThan(120, mb_strlen($text), '브라우저 렌더 본문 채택');
    }

    public function test_htt_p_실패해도_브라우저_렌더로_복구한다(): void
    {
        Http::fake(['arca.live/*' => Http::response('', 403)]); // 아카 봇 차단
        $rendered = '<html><body><div class="article-content">'.str_repeat('실전 편성 본문. ', 30).'</div></body></html>';
        $this->fakeBrowser($rendered);

        $text = app(GuideBodyFetcher::class)->fetch($this->guidePost('arca', 'https://arca.live/b/bluearchive/2'));

        $this->assertStringContainsString('실전 편성 본문', $text);
    }

    public function test_htt_p_실패_브라우저도_실패면_null(): void
    {
        Http::fake(['gall.dcinside.com/*' => Http::response('', 404)]);
        $this->fakeBrowser(null);

        $this->assertNull(app(GuideBodyFetcher::class)->fetch($this->guidePost()));
    }

    public function test_셀렉터_미정의_소스는_요청_없이_스킵한다(): void
    {
        Http::fake();
        $this->fakeBrowser(null);

        $this->assertNull(app(GuideBodyFetcher::class)->fetch($this->guidePost('theqoo', 'https://theqoo.net/1')));
        Http::assertNothingSent();
    }
}
