<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\LoungeEventDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoungeEventDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['event-calendar.sources.lounge.lounges' => [
            ['lounge' => 'nikke', 'board' => 11, 'label' => '니케'],
        ]]);
    }

    private function fakeFeed(array $feeds): void
    {
        Http::fake([
            'comm-api.game.naver.com/*' => Http::response(['content' => ['feeds' => array_map(
                fn ($f) => ['feed' => $f], $feeds,
            )]]),
        ]);
    }

    public function test_collects_popup_store_from_notice_feed(): void
    {
        $this->fakeFeed([
            [
                'feedId' => 1001,
                'title' => '『2026 여름 팝업스토어』 사전 안내',
                'contents' => json_encode(['document' => ['sections' => [
                    ['text' => '기간: 7월 10일(금) ~ 7월 27일(월)'],
                    ['text' => '장소: 홍대 AK플라자 5층'],
                ]]]),
                'createdDate' => '2026-07-08 12:00:00',
            ],
            [
                'feedId' => 1002,
                'title' => '『2026 여름 팝업스토어』 입장 퀴즈 안내', // 부속 공지 — 제목 포함관계로 제거
                'contents' => json_encode(['document' => ['sections' => [['text' => '7월 17일 ~ 7월 30일 진행']]]]),
                'createdDate' => '2026-07-16 12:00:00',
            ],
            [
                'feedId' => 1003,
                'title' => '오케스트라 콘서트 온라인 상영 안내', // 온라인 — 제외
                'contents' => json_encode(['document' => [['text' => '7월 1일']]]),
                'createdDate' => '2026-07-01 12:00:00',
            ],
            [
                'feedId' => 1004,
                'title' => '신규 캐릭터 업데이트 공지', // 오프라인 키워드 없음 — 제외
                'contents' => json_encode(['document' => [['text' => '7월 3일']]]),
                'createdDate' => '2026-07-02 12:00:00',
            ],
        ]);

        $events = app(LoungeEventDriver::class)->collect();

        $this->assertCount(1, $events, '팝업 본공지만(부속 공지·온라인·게임 내 공지 제외)');
        $e = $events[0];
        $this->assertSame('lounge-nikke-1001', $e->externalKey);
        $this->assertSame(EventKind::Expo, $e->kind);
        $this->assertSame('니케 2026 여름 팝업스토어', $e->title, '라벨 접두 + 장식괄호·안내 꼬리 제거');
        $this->assertSame('2026-07-10', $e->startsOn);
        $this->assertSame('2026-07-27', $e->endsOn);
        $this->assertSame('홍대 AK플라자 5층', $e->venue);
    }

    public function test_year_inference_for_next_january_event(): void
    {
        // 12월 작성 공지의 "1월 10일" — 이듬해로 보간
        $this->fakeFeed([[
            'feedId' => 2001,
            'title' => '윈터 팝업스토어 안내',
            'contents' => json_encode(['document' => [['text' => '기간: 1월 10일 ~ 1월 20일']]]),
            'createdDate' => '2026-12-20 12:00:00',
        ]]);

        $events = app(LoungeEventDriver::class)->collect();

        $this->assertSame('2027-01-10', $events[0]->startsOn);
        $this->assertSame('2027-01-20', $events[0]->endsOn);
    }

    public function test_api_failure_returns_empty(): void
    {
        Http::fake(['comm-api.game.naver.com/*' => Http::response(null, 500)]);

        $this->assertSame([], app(LoungeEventDriver::class)->collect());
    }
}
