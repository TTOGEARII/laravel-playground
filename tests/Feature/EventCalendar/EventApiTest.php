<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvents(): void
    {
        Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'genre' => 'jpop', 'title' => 'YOASOBI 내한공연', 'starts_on' => '2026-08-10', 'time_text' => '오후 7시', 'venue' => '킨텍스', 'price_text' => 'R석 154,000원', 'detail_url' => 'https://festivallife.kr/x']);
        Event::create(['source' => 'festivallife', 'external_key' => '2', 'kind' => 'concert', 'genre' => 'other', 'title' => 'Deep Purple 내한공연', 'starts_on' => '2026-08-20']);
        Event::create(['source' => 'comicworld', 'external_key' => 'comic-335', 'kind' => 'doujin', 'title' => '코믹월드 335 일산', 'starts_on' => '2026-08-15', 'ends_on' => '2026-08-16', 'ticket_links' => [['label' => '티켓 구매', 'url' => 'https://comicw.net/t']]]);
        Event::create(['source' => 'manual', 'external_key' => 'agf-2026', 'kind' => 'expo', 'title' => 'AGF 2026', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-06']);
        // 7월 말~8월 초 걸치는 행사(월 겹침 검증)
        Event::create(['source' => 'manual', 'external_key' => 'span', 'kind' => 'expo', 'title' => '월말 전시', 'starts_on' => '2026-07-30', 'ends_on' => '2026-08-02']);
    }

    public function test_month_endpoint_returns_overlapping_events(): void
    {
        $this->seedEvents();

        $res = $this->getJson('/api/event-calendar/events?year=2026&month=8')->assertOk();
        $titles = collect($res->json('data'))->pluck('title');

        $this->assertCount(4, $titles); // 8월 3건 + 7/30~8/2 걸침 1건
        $this->assertTrue($titles->contains('월말 전시'), '월 경계에 걸친 행사 포함');
        $this->assertFalse($titles->contains('AGF 2026'), '다른 달 행사 제외');
    }

    public function test_jpop_only_filter_keeps_doujin_and_expo(): void
    {
        $this->seedEvents();

        $titles = collect($this->getJson('/api/event-calendar/events?year=2026&month=8&jpop_only=1')->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('YOASOBI 내한공연'));
        $this->assertFalse($titles->contains('Deep Purple 내한공연'), 'other 공연 제외');
        $this->assertTrue($titles->contains('코믹월드 335 일산'), '행사류는 J-pop 필터 영향 없음');
    }

    public function test_kind_filters(): void
    {
        $this->seedEvents();

        $this->assertCount(2, $this->getJson('/api/event-calendar/events?year=2026&month=8&kind=concert')->json('data'));
        $titles = collect($this->getJson('/api/event-calendar/events?year=2026&month=8&kind=events')->json('data'))->pluck('title');
        $this->assertCount(2, $titles, '동인+기업');
        $this->assertFalse($titles->contains('YOASOBI 내한공연'));
    }

    public function test_upcoming_and_detail(): void
    {
        $this->seedEvents();
        $this->travelTo('2026-08-01');

        $up = $this->getJson('/api/event-calendar/events?upcoming=1&limit=3')->assertOk()->json('data');
        $this->assertSame('월말 전시', $up[0]['title'], '진행 중 행사(종료일 이후) 포함·시작일순');

        $id = Event::where('external_key', '1')->first()->id;
        $this->getJson("/api/event-calendar/events/{$id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'YOASOBI 내한공연')
            ->assertJsonPath('data.price_text', 'R석 154,000원')
            ->assertJsonPath('data.detail_url', 'https://festivallife.kr/x');

        $this->getJson('/api/event-calendar/events/999999')->assertNotFound();
    }

    public function test_month_response_includes_ticket_opens_and_upcoming_opens_list(): void
    {
        $this->seedEvents();
        // 8월에 티켓이 오픈되는 9월 공연(공연일은 다음 달 — 오픈일 기준으로 8월 캘린더에 떠야 함)
        Event::create(['source' => 'festivallife', 'external_key' => '9', 'kind' => 'concert', 'genre' => 'jpop', 'title' => 'Fujii Kaze 내한공연', 'starts_on' => '2026-09-20', 'ticket_opens_on' => '2026-08-05', 'ticket_open_text' => '8월 5일 (수) 오후 8시']);

        $res = $this->getJson('/api/event-calendar/events?year=2026&month=8')->assertOk();
        $opens = collect($res->json('ticket_opens'));
        $this->assertCount(1, $opens);
        $this->assertSame('Fujii Kaze 내한공연', $opens[0]['title']);
        $this->assertSame('2026-08-05', $opens[0]['ticket_opens_on']);
        $this->assertFalse(collect($res->json('data'))->pluck('title')->contains('Fujii Kaze 내한공연'), '공연일(9월)은 8월 행사 목록에 없음');

        // 다가오는 티켓 오픈(임박순)
        $this->travelTo('2026-08-01');
        $list = $this->getJson('/api/event-calendar/events?ticket_opens=1')->assertOk()->json('data');
        $this->assertSame('Fujii Kaze 내한공연', $list[0]['title']);
    }

    public function test_pages_render(): void
    {
        $this->seedEvents();
        $id = Event::first()->id;

        $this->get('/event-calendar')->assertOk()->assertSee('행사 캘린더');
        $this->get("/event-calendar/{$id}")->assertOk()->assertSee('data-event-id="'.$id.'"', false);
        $this->get('/event-calendar/999999')->assertNotFound();
    }
}
