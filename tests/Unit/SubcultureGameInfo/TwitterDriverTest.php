<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\TwitterDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TwitterDriver 화이트박스: nitter RSS 를 Http::fake 로 목킹.
 * 코드 마커가 있는 트윗에서만 코드 토큰을 뽑고, 마커 없으면 무시한다.
 */
class TwitterDriverTest extends TestCase
{
    private function fakeRss(string ...$tweets): void
    {
        $items = implode('', array_map(
            fn ($t) => "<item><description><![CDATA[{$t}]]></description></item>",
            $tweets
        ));
        Http::fake([
            'nitter.net/*' => Http::response("<rss><channel>{$items}</channel></rss>", 200),
        ]);
    }

    public function test_extracts_code_from_code_marker_tweet(): void
    {
        $this->fakeRss('공명자 여러분! 교환 코드 WUTHERINGGIFT 를 입력하고 보상을 받으세요. #명조');

        $dtos = (new TwitterDriver)->collect('wuthering', []);

        $this->assertCount(1, $dtos);
        $this->assertSame('WUTHERINGGIFT', $dtos[0]->code);
        $this->assertSame('twitter', $dtos[0]->source);
        $this->assertSame(SourceType::Aggregator, $dtos[0]->sourceType);
    }

    public function test_ignores_tweets_without_code_marker(): void
    {
        // 코드 마커(교환 코드 등)가 없으면, 코드처럼 보이는 토큰이 있어도 무시.
        $this->fakeRss('신규 트레일러 EISODUS 가 공개되었습니다! 많은 관심 부탁드려요 #명조');

        $this->assertSame([], (new TwitterDriver)->collect('wuthering', []));
    }

    public function test_returns_empty_for_unmapped_game(): void
    {
        Http::fake();
        $this->assertSame([], (new TwitterDriver)->collect('genshin', []));
        Http::assertNothingSent();  // 계정 매핑 없으면 요청조차 안 함
    }

    public function test_returns_empty_when_nitter_unavailable(): void
    {
        // nitter 다운(5xx) → 빈 배열로 무해하게 폴백.
        Http::fake(['nitter.net/*' => Http::response('', 503)]);
        $this->assertSame([], (new TwitterDriver)->collect('wuthering', []));
    }
}
