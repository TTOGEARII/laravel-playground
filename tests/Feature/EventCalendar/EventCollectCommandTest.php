<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventCollectCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'event-calendar.sources.festivallife.pages' => 1,
            'event-calendar.sources.festivallife.delay_ms' => 0,
            // 일러스타는 Playwright 사이드카(실 프로세스)라 커맨드 테스트에서는 비활성 — 드라이버 자체는 IllustarDriverTest 가 Process::fake 로 검증
            'event-calendar.sources.illustar.enabled' => false,
            // 전시장 드라이버도 커맨드 테스트에서는 비활성(실 HTTP 방지 — VenueDriversTest 가 검증)
            'event-calendar.sources.kintex.enabled' => false,
            'event-calendar.sources.setec.enabled' => false,
            'event-calendar.sources.coex.enabled' => false,
            'event-calendar.sources.lounge.enabled' => false,
        ]);
    }

    private function fakeAll(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'comicw.net')) {
                // comic 타입에만 아이템 반환(mongu 는 빈 배열 — 실제로도 타입별 응답이 다르다)
                return Http::response($request['type'] === 'comic' ? [[
                    'title' => '코믹월드 335 일산', 'place' => '킨텍스', 'startDate' => '2026-08-15',
                    'endDate' => '2026-08-16', 'submitLink' => 'https://comicw.net/e/335',
                ]] : []);
            }
            if (str_contains($url, 'idx=101')) {
                return Http::response('<div class="board_view"><p>일정: 2026년 9월 1일 (화) 오후 7시</p><p>장소: 예스24 라이브홀</p></div>');
            }

            return Http::response('<a href="/concert_k/?bmode=view&amp;idx=101&amp;t=board"><span class="title">테스트 공연</span></a>');
        });
    }

    public function test_collect_syncs_all_sources_idempotently(): void
    {
        $this->fakeAll();

        $this->artisan('event-calendar:collect')->assertSuccessful();

        $this->assertSame(2, Event::count());
        $comic = Event::where('source', 'comicworld')->first();
        $this->assertSame('2026-08-15', $comic->starts_on->toDateString());
        $concert = Event::where('source', 'festivallife')->first();
        $this->assertSame('예스24 라이브홀', $concert->venue);

        // 재실행해도 중복 생성 없음(멱등) — festivallife 는 기존 글 상세 요청도 생략
        $this->artisan('event-calendar:collect')->assertSuccessful();
        $this->assertSame(2, Event::count());
    }

    public function test_source_option_limits_to_one_driver(): void
    {
        $this->fakeAll();

        $this->artisan('event-calendar:collect --source=comicworld')->assertSuccessful();

        $this->assertSame(1, Event::count());
        $this->assertSame('comicworld', Event::first()->source);
    }

    public function test_one_source_failure_does_not_block_others(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'comicw.net')) {
                return Http::response($request['type'] === 'comic' ? [[
                    'title' => '코믹월드 335 일산', 'place' => '킨텍스', 'startDate' => '2026-08-15', 'submitLink' => 'https://comicw.net/e/335',
                ]] : []);
            }

            return Http::response(null, 500); // festivallife 다운
        });

        $this->artisan('event-calendar:collect')->assertSuccessful();

        $this->assertSame(1, Event::count(), 'festivallife 실패해도 코믹월드는 저장');
    }

    public function test_import_manual_events_idempotently(): void
    {
        $file = base_path('database/data/events/agf.json');

        $this->artisan("event-calendar:import {$file}")->assertSuccessful();
        $this->artisan("event-calendar:import {$file}")->assertSuccessful();

        $this->assertSame(1, Event::count());
        $agf = Event::first();
        $this->assertSame('manual', $agf->source);
        $this->assertSame('agf-2026', $agf->external_key);
        $this->assertSame('2026-12-04', $agf->starts_on->toDateString());
        $this->assertSame('2026-12-06', $agf->ends_on->toDateString());
    }
}
