<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\MyconEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MyconEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    /** mycon 상세와 같은 형태의 JSON-LD(+og:image) HTML 픽스처. */
    private function myconHtml(array $ldOverrides = []): string
    {
        $ld = array_replace_recursive([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => 'Chilli Beans. Asia Tour 2026 in Seoul',
            'startDate' => '2026-08-08',
            'location' => ['@type' => 'Place', 'name' => '예스24 원더로크홀'],
            'offers' => [[
                '@type' => 'Offer',
                'url' => 'https://ticket.yes24.com/Perf/58454',
                'price' => 99000,
                'priceCurrency' => 'KRW',
                'validFrom' => '2026-05-20T11:00:00+00:00', // UTC = KST 5/20 20:00
            ]],
        ], $ldOverrides);

        return '<html><head><meta property="og:image" content="https://mycon.me/img/posters/abc"/></head>'
            .'<body><script type="application/ld+json">'.json_encode($ld, JSON_UNESCAPED_UNICODE).'</script></body></html>';
    }

    private function makeConcert(array $attrs = []): Event
    {
        return Event::create(array_merge([
            'source' => 'jpoptistory', 'external_key' => 'jpt-1', 'kind' => 'concert', 'genre' => 'jpop',
            'title' => 'Chilli Beans. Asia Tour 2026 in Seoul', 'starts_on' => '2026-08-08',
            'extra' => ['mycon_url' => 'https://mycon.me/concert/1463'],
        ], $attrs));
    }

    public function test_enriches_ticket_open_price_link_poster_from_json_ld(): void
    {
        $this->travelTo('2026-05-01');
        $event = $this->makeConcert();
        Http::fake(['mycon.me/*' => Http::response($this->myconHtml())]);

        $stats = app(MyconEnrichmentService::class)->enrich();

        $this->assertSame(['enriched' => 1, 'checked' => 1, 'failed' => 0], $stats);
        $event->refresh();
        $this->assertSame('2026-05-20', $event->ticket_opens_on->toDateString(), 'validFrom UTC → KST 날짜');
        $this->assertSame('5월 20일 (수) 오후 8시', $event->ticket_open_text, 'KST 시각 문구');
        $this->assertSame('99,000원~', $event->price_text);
        $this->assertSame('https://ticket.yes24.com/Perf/58454', $event->ticket_links[0]['url'], '원 예매처 딥링크');
        $this->assertStringContainsString('YES24', $event->ticket_links[0]['label']);
        $this->assertSame('https://mycon.me/img/posters/abc', $event->poster_url);
    }

    public function test_sentinel_9999_means_unannounced_and_existing_values_kept(): void
    {
        $this->travelTo('2026-05-01');
        $event = $this->makeConcert(['price_text' => '기존 가격', 'venue' => '기존 장소']);
        Http::fake(['mycon.me/*' => Http::response($this->myconHtml([
            'offers' => [['validFrom' => '9999-12-31T14:59:00+00:00', 'url' => null, 'price' => null]],
        ]))]);

        app(MyconEnrichmentService::class)->enrich();

        $event->refresh();
        $this->assertNull($event->ticket_opens_on, '9999 sentinel = 오픈일 미정');
        $this->assertSame('기존 가격', $event->price_text, '기존 값은 빈 파싱으로 지워지지 않음');
    }

    public function test_targets_only_future_concerts_with_pending_opens(): void
    {
        $this->travelTo('2026-05-01');
        $this->makeConcert(['external_key' => 'past', 'starts_on' => '2026-04-01']); // 지난 공연 — 제외
        $this->makeConcert(['external_key' => 'done', 'starts_on' => '2026-08-01', 'ticket_opens_on' => '2026-04-20']); // 오픈 끝 — 제외
        $this->makeConcert(['external_key' => 'no-link', 'extra' => []]); // mycon 링크 없음 — 제외
        $target = $this->makeConcert(['external_key' => 'target']);
        Http::fake(['mycon.me/*' => Http::response($this->myconHtml())]);

        $stats = app(MyconEnrichmentService::class)->enrich();

        $this->assertSame(1, $stats['checked'], '미래 공연 + 오픈 미확정 + mycon 링크 있는 행만');
        $this->assertSame('2026-05-20', $target->fresh()->ticket_opens_on->toDateString());
    }

    public function test_http_failure_is_counted_and_harmless(): void
    {
        $this->travelTo('2026-05-01');
        $event = $this->makeConcert();
        Http::fake(['mycon.me/*' => Http::response(null, 500)]);

        $stats = app(MyconEnrichmentService::class)->enrich();

        $this->assertSame(['enriched' => 0, 'checked' => 1, 'failed' => 1], $stats);
        $this->assertNull($event->fresh()->ticket_opens_on);
    }
}
