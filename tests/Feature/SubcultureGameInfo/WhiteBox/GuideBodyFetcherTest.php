<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Services\SubcultureGameInfo\Raids\GuideBodyFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: 공략글 본문 수집기의 셀렉터 파싱·방어적 실패 처리.
 */
class GuideBodyFetcherTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_설정된_셀렉터로_본문_텍스트를_추출한다(): void
    {
        Http::fake([
            'gall.dcinside.com/*' => Http::response(
                '<html><body><div class="head">헤더</div><div class="write_div"><p>미카 없으면 <b>사키</b>로 대체</p><script>track()</script></div></body></html>',
            ),
        ]);

        $text = app(GuideBodyFetcher::class)->fetch($this->guidePost());

        $this->assertSame('미카 없으면 사키로 대체', $text);
    }

    public function test_본문_영역이_없으면_null_을_반환한다(): void
    {
        Http::fake(['gall.dcinside.com/*' => Http::response('<html><body><div class="other">본문 아님</div></body></html>')]);

        $this->assertNull(app(GuideBodyFetcher::class)->fetch($this->guidePost()));
    }

    public function test_htt_p_실패는_null_로_폴백한다(): void
    {
        Http::fake(['gall.dcinside.com/*' => Http::response('', 404)]);

        $this->assertNull(app(GuideBodyFetcher::class)->fetch($this->guidePost()));
    }

    public function test_셀렉터_미정의_소스는_요청_없이_스킵한다(): void
    {
        Http::fake();

        $this->assertNull(app(GuideBodyFetcher::class)->fetch($this->guidePost('theqoo', 'https://theqoo.net/1')));
        Http::assertNothingSent();
    }
}
