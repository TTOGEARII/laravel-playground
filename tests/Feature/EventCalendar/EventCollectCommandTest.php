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
            // 사이드카(Playwright 실 프로세스)·실 HTTP 드라이버는 커맨드 테스트에서 비활성 —
            // 각 드라이버는 전용 테스트(Process/Http fake)가 검증한다
            'event-calendar.sources.jpoptistory.enabled' => false,
            'event-calendar.sources.illustar.enabled' => false,
            'event-calendar.sources.kintex.enabled' => false,
            'event-calendar.sources.setec.enabled' => false,
            'event-calendar.sources.coex.enabled' => false,
            'event-calendar.sources.lounge.enabled' => false,
            'event-calendar.mycon.enabled' => false,
            'event-calendar.x_crosscheck.enabled' => false,
        ]);
    }

    private function fakeAll(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'comicw.net')) {
                // comic 타입에만 아이템 반환(mongu 는 빈 배열 — 실제로도 타입별 응답이 다르다)
                return Http::response($request['type'] === 'comic' ? [[
                    'title' => '코믹월드 335 일산', 'place' => '킨텍스', 'startDate' => '2026-08-15',
                    'endDate' => '2026-08-16', 'submitLink' => 'https://comicw.net/e/335',
                ]] : []);
            }

            return Http::response([], 404);
        });
    }

    public function test_collect_syncs_sources_idempotently(): void
    {
        $this->fakeAll();

        $this->artisan('event-calendar:collect')->assertSuccessful();

        $this->assertSame(1, Event::count());
        $comic = Event::where('source', 'comicworld')->first();
        $this->assertSame('2026-08-15', $comic->starts_on->toDateString());

        // 재실행해도 중복 생성 없음(멱등)
        $this->artisan('event-calendar:collect')->assertSuccessful();
        $this->assertSame(1, Event::count());
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
        // 코믹월드 comic 타입만 성공, mongu 는 500 — 실패 타입이 성공 타입을 막지 않는다
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'comicw.net') && $request['type'] === 'comic') {
                return Http::response([[
                    'title' => '코믹월드 335 일산', 'place' => '킨텍스', 'startDate' => '2026-08-15', 'submitLink' => 'https://comicw.net/e/335',
                ]]);
            }

            return Http::response(null, 500);
        });

        $this->artisan('event-calendar:collect')->assertSuccessful();

        $this->assertSame(1, Event::count());
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
