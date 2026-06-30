<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\TwitterDriver;
use Illuminate\Support\Carbon;
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

    public function test_parses_expiry_and_marks_past_codes_expired(): void
    {
        // 실제 명조 트윗 포맷: '리딤 코드는 [A], [B]입니다. 유효 기간은 YYYY년 M월 D일 HH:MM까지'
        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->fakeRss('『명조』 특별 방송의 리딤 코드는 [MECHANISMCITY], [INTOTHEFOG], [REUNION]입니다. 유효 기간은 2026년 6월 29일 00:59까지입니다.');

        $byCode = collect((new TwitterDriver)->collect('wuthering', []))->keyBy('code');

        $this->assertSame('2026-06-29', $byCode['MECHANISMCITY']->expiresAt?->format('Y-m-d'));
        $this->assertSame(CodeStatus::Expired, $byCode['MECHANISMCITY']->status);  // 7/1 기준 만료
        $this->assertSame(CodeStatus::Expired, $byCode['REUNION']->status);

        Carbon::setTestNow();
    }

    public function test_future_expiry_is_active(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->fakeRss('교환 코드 LIVECODE777 를 입력하세요. 유효 기간은 2026년 12월 31일까지입니다.');

        $dto = (new TwitterDriver)->collect('wuthering', [])[0];

        $this->assertSame('2026-12-31', $dto->expiresAt?->format('Y-m-d'));
        $this->assertSame(CodeStatus::Active, $dto->status);

        Carbon::setTestNow();
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
