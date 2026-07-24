<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\FestivalLifeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivalLifeDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['event-calendar.sources.festivallife.pages' => 1, 'event-calendar.sources.festivallife.delay_ms' => 0]);
    }

    // 실측 구조: 일반 글에도 숨김(display:none) 공지 배지가 들어있고, 진짜 공지만 배지가 보인다.
    private const LIST_HTML = <<<'HTML'
        <div class="_post_item_wrap"><a class="post_link_wrap" href="/concert_k/?q=YToxOnt9&bmode=view&idx=172118163&t=board"><div class="title"><em class="notice-block" style="display: none">공지</em> Kings of Convenience 내한공연</div></a></div>
        <div class="_post_item_wrap"><a class="post_link_wrap" href="/concert_k/?q=YToxOnt9&bmode=view&idx=172200001&t=board"><div class="title"><em class="notice-block" style="display: none">공지</em> YOASOBI 내한공연</div></a></div>
        <div class="_post_item_wrap"><a href="/concert_k/?q=YToxOnt9&bmode=view&idx=999&t=board"><div class="title"><em class="notice-block">공지</em> 게시판 이용 안내</div></a></div>
        HTML;

    private const DETAIL_KOC = <<<'HTML'
        <meta property="og:title" content="Kings of Convenience 내한공연 | 페스티벌라이프">
        <meta property="og:image" content="https://cdn.imweb.me/upload/poster-koc.jpg">
        <div class="board_view"><p>일정: 2026년 11월 18일 (수) 오후 8시</p><p>장소: 세종문화회관 대극장</p>
        <p>가격: R석 143,000원, S석 132,000원</p><p>오픈: 7월 7일 (화) 오후 4시</p><p>예매: 세종문화티켓, 예스24, 멜론티켓</p></div>
        HTML;

    private const DETAIL_YOASOBI = <<<'HTML'
        <meta property="og:title" content="YOASOBI 내한공연">
        <div class="board_view"><p>일정: 2026년 1월 17일 (토) ~ 18일 (일) 오후 6시</p><p>장소: 킨텍스</p></div>
        HTML;

    private function fakeSite(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'idx=172118163')) {
                return Http::response(self::DETAIL_KOC);
            }
            if (str_contains($url, 'idx=172200001')) {
                return Http::response(self::DETAIL_YOASOBI);
            }

            return Http::response(self::LIST_HTML);
        });
    }

    public function test_parses_list_and_detail_labels(): void
    {
        $this->fakeSite();

        $events = app(FestivalLifeDriver::class)->collect();

        $this->assertCount(2, $events, '공지 글은 제외');
        $koc = $events[0];
        $this->assertSame('festivallife', $koc->source);
        $this->assertSame('172118163', $koc->externalKey);
        $this->assertSame(EventKind::Concert, $koc->kind);
        $this->assertSame('Kings of Convenience 내한공연', $koc->title, 'og:title 에서 사이트명 접미 제거');
        $this->assertSame('2026-11-18', $koc->startsOn);
        $this->assertNull($koc->endsOn);
        $this->assertSame('오후 8시', $koc->timeText);
        $this->assertSame('세종문화회관 대극장', $koc->venue);
        $this->assertStringContainsString('R석 143,000원', $koc->priceText);
        $this->assertStringContainsString('7월 7일', $koc->ticketOpenText);
        $this->assertSame('https://cdn.imweb.me/upload/poster-koc.jpg', $koc->posterUrl);
        $this->assertStringContainsString('세종문화티켓', $koc->extra['booking_text']);

        // 이틀 공연: "1월 17일 ~ 18일" 범위 보간
        $yoasobi = $events[1];
        $this->assertSame('2026-01-17', $yoasobi->startsOn);
        $this->assertSame('2026-01-18', $yoasobi->endsOn);
    }

    public function test_skip_keys_avoid_detail_fetch(): void
    {
        $this->fakeSite();

        $events = app(FestivalLifeDriver::class)->collect(['172118163']);

        $this->assertCount(1, $events, '이미 저장된 글은 상세 방문·반환 생략');
        $this->assertSame('172200001', $events[0]->externalKey);
        Http::assertSentCount(2); // 목록 1 + 신규 상세 1 (기존 글 상세 요청 없음)
    }
}
