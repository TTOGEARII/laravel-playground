<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\EventNotifyService;
use App\Services\EventCalendar\EventSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_ticket_opens_on_infers_year_from_event_date(): void
    {
        // 공연과 같은 해 오픈
        $this->assertSame('2026-07-07', EventSyncService::parseTicketOpensOn('7월 7일 (화) 오후 4시', '2026-11-18'));
        // 연말 오픈 → 연초 공연: 오픈일이 공연일보다 뒤면 전년으로 보간
        $this->assertSame('2025-12-20', EventSyncService::parseTicketOpensOn('12월 20일 (금) 오후 8시', '2026-01-17'));
        // 연도 명시는 그대로
        $this->assertSame('2026-05-01', EventSyncService::parseTicketOpensOn('2026년 5월 1일', '2026-11-01'));
        // 파싱 불가·정보 없음은 null
        $this->assertNull(EventSyncService::parseTicketOpensOn('추후 공지', '2026-11-01'));
        $this->assertNull(EventSyncService::parseTicketOpensOn(null, '2026-11-01'));
    }

    public function test_today_ticket_opens_and_new_event_digest(): void
    {
        $this->travelTo('2026-07-24 09:00:00');

        $opensToday = Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'title' => 'YOASOBI 내한공연', 'starts_on' => '2026-11-18', 'ticket_opens_on' => '2026-07-24', 'notified_at' => now()->subDay()]);
        Event::create(['source' => 'festivallife', 'external_key' => '2', 'kind' => 'concert', 'title' => '오픈 지난 공연', 'starts_on' => '2026-10-01', 'ticket_opens_on' => '2026-07-20', 'notified_at' => now()->subDay()]);
        $newEvent = Event::create(['source' => 'comicworld', 'external_key' => 'c-1', 'kind' => 'doujin', 'title' => '코믹월드 337', 'starts_on' => '2026-10-03']);
        Event::create(['source' => 'manual', 'external_key' => 'past', 'kind' => 'expo', 'title' => '지난 행사(백필)', 'starts_on' => '2026-01-01']);

        $service = app(EventNotifyService::class);

        $opens = $service->todayTicketOpens();
        $this->assertCount(1, $opens);
        $this->assertSame($opensToday->id, $opens->first()->id, '오늘 오픈만');

        $new = $service->unnotifiedNewEvents();
        $this->assertCount(1, $new);
        $this->assertSame($newEvent->id, $new->first()->id, '미발송 + 미래 행사만(지난 행사 백필 제외)');
        $this->assertSame('코믹월드 337', $service->digestBody($new));

        $service->markNotified($new);
        $this->assertCount(0, $service->unnotifiedNewEvents(), '표시 후 재발송 없음');
    }

    public function test_notify_command_skips_without_vapid(): void
    {
        config(['services.webpush.vapid_public' => null, 'services.webpush.public_key' => null]);

        $this->artisan('event-calendar:notify')->assertSuccessful();
    }
}
