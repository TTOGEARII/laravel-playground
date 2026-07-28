<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\XCrossCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XCrossCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRss(array $tweets): void
    {
        $items = collect($tweets)->map(fn ($t) => '<item><title>'.htmlspecialchars($t['text']).'</title>'
            .'<pubDate>'.($t['pubDate'] ?? 'Tue, 28 Jul 2026 09:00:00 GMT').'</pubDate>'
            .'<link>'.($t['link'] ?? 'https://x.com/FstvlLife/status/1').'</link></item>')->implode('');
        Http::fake(['*/FstvlLife/rss' => Http::response('<rss><channel>'.$items.'</channel></rss>')]);
    }

    private function makeConcert(array $attrs = []): Event
    {
        return Event::create(array_merge([
            'source' => 'jpoptistory', 'external_key' => 'jpt-1', 'kind' => 'concert', 'genre' => 'jpop',
            'title' => 'Vaundy ASIA ARENA TOUR 2026 “HORO” IN SEOUL', 'starts_on' => '2026-09-19', 'ends_on' => '2026-09-20',
        ], $attrs));
    }

    public function test_fills_missing_open_date_and_confirms_schedule(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert();
        $this->fakeRss([[
            'text' => '[티켓 오픈 정보] Vaundy ASIA ARENA TOUR — 티켓 오픈: 8월 11일 (화) 오후 8시 / 공연: 9월 19일~20일 인스파이어 아레나',
        ]]);

        $stats = app(XCrossCheckService::class)->run();

        $this->assertSame(1, $stats['filled']);
        $event->refresh();
        $this->assertSame('2026-08-11', $event->ticket_opens_on->toDateString(), '트윗에서 오픈일 보강(연도 보간)');
        $this->assertSame('8월 11일 오후 8시', $event->ticket_open_text);
        $this->assertSame('x', $event->extra['ticket_open_source']);
        $this->assertSame('ok', $event->extra['xcheck_schedule'], '공연 기간 안 날짜 언급 → 일정 교차 확인');
    }

    public function test_mismatch_is_recorded_but_not_overwritten(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert(['ticket_opens_on' => '2026-08-10']); // mycon 이 확보한 값
        $this->fakeRss([['text' => 'Vaundy 내한 티켓 오픈: 8월 11일 (화) 오후 8시']]);

        $stats = app(XCrossCheckService::class)->run();

        $this->assertSame(1, $stats['mismatched']);
        $event->refresh();
        $this->assertSame('2026-08-10', $event->ticket_opens_on->toDateString(), 'mycon 구조화 데이터 우선 — 덮지 않음');
        $this->assertSame('mismatch', $event->extra['xcheck']);
        $this->assertSame('2026-08-11', $event->extra['xcheck_open']);
    }

    public function test_matching_open_date_marks_confirmed(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert(['ticket_opens_on' => '2026-08-11']);
        $this->fakeRss([['text' => 'Vaundy 내한 예매 오픈: 8월 11일 (화) 오후 8시']]);

        $stats = app(XCrossCheckService::class)->run();

        $this->assertSame(1, $stats['confirmed']);
        $this->assertSame('ok', $event->fresh()->extra['xcheck']);
    }

    public function test_unrelated_tweets_do_not_touch_events(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert();
        $this->fakeRss([['text' => 'YOASOBI 단독 콘서트 티켓 오픈: 8월 1일 (토) 오후 8시']]);

        app(XCrossCheckService::class)->run();

        $this->assertNull($event->fresh()->ticket_opens_on, '아티스트 토큰 불일치 — 무시');
    }

    public function test_rss_failure_is_harmless_skip(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);
        $this->makeConcert();

        $stats = app(XCrossCheckService::class)->run();

        $this->assertTrue($stats['skipped']);
    }
}
