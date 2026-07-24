<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\EventSyncService;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 교차 소스 공연 병합(EventSyncService) — 같은 날·같은 아티스트 공연이 여러 소스에서 오면
 * festivallife(상세 전문)로 승격하고, 그 외 소스는 기존 행을 유지한 채 스킵한다.
 */
class EventMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_concert_from_other_source_is_skipped(): void
    {
        Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'title' => 'Reol 내한공연', 'starts_on' => '2026-07-18']);

        $stats = app(EventSyncService::class)->sync([
            new CollectedEventData(source: 'lounge', externalKey: 'lg-1', kind: EventKind::Concert,
                title: 'Reol Oneman Live 2026 in SEOUL', startsOn: '2026-07-18',
                ticketLinks: [['label' => '예매하기', 'url' => 'https://t.example/reol']]),
            new CollectedEventData(source: 'lounge', externalKey: 'lg-2', kind: EventKind::Concert,
                title: 'YUINA Fan Meeting', startsOn: '2026-07-18'),
        ]);

        $this->assertSame(1, $stats['created'], '같은 날 다른 아티스트(YUINA)만 생성');
        $this->assertSame(1, $stats['skipped'], 'Reol 은 festivallife 와 중복 — 스킵');
        $dup = Event::where('source', 'festivallife')->first();
        $this->assertSame('https://t.example/reol', $dup->ticket_links[0]['url'], '빈 예매 링크는 보강');
    }

    public function test_festivallife_detail_takes_over_discovery_row(): void
    {
        // 다른 소스가 먼저 만든 공연 행 — festivallife 상세가 오면 같은 행을 승격한다
        $discovered = Event::create([
            'source' => 'lounge', 'external_key' => 'lg-abc', 'kind' => 'concert', 'genre' => 'jpop',
            'title' => 'Reol Oneman Live 2026 in SEOUL', 'starts_on' => '2026-07-18',
            'ticket_links' => [['label' => '예매하기', 'url' => 'https://t.example/reol']],
        ]);

        $stats = app(EventSyncService::class)->sync([new CollectedEventData(
            source: 'festivallife',
            externalKey: '172999',
            kind: EventKind::Concert,
            title: 'Reol 내한공연',
            startsOn: '2026-07-18',
            timeText: '오후 6시',
            venue: '예스24 원더로크홀',
            priceText: '스탠딩 110,000원',
            ticketOpenText: '6월 1일 (월) 오후 8시',
            posterUrl: 'https://cdn.imweb.me/reol.jpg',
            detailUrl: 'https://festivallife.kr/concert_k/?idx=172999',
        )]);

        $this->assertSame(1, $stats['updated'], '신규 생성이 아니라 기존 행 승격');
        $this->assertSame(1, Event::count(), '중복 행 없음');
        $event = $discovered->fresh();
        $this->assertSame('festivallife', $event->source, '소스 승격');
        $this->assertSame('172999', $event->external_key);
        $this->assertSame('Reol 내한공연', $event->title);
        $this->assertSame('스탠딩 110,000원', $event->price_text, 'festivallife 상세 반영');
        $this->assertSame('2026-06-01', $event->ticket_opens_on->toDateString(), '티켓오픈일 파싱');
        $this->assertSame('jpop', $event->genre, '기존 확정 장르 유지');
        $this->assertSame('https://t.example/reol', $event->ticket_links[0]['url'], '기존 예매 링크 보존');
    }
}
