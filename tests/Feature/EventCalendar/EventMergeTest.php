<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\EventSyncService;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 교차 소스 공연 중복 방지(EventSyncService) — 같은 날·같은 아티스트 공연이 다른 소스에서 오면
 * 기존 행을 유지하고 빈 예매 링크만 보강 후 스킵한다(먼저 온 쪽이 정본).
 */
class EventMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_concert_from_other_source_is_skipped(): void
    {
        Event::create(['source' => 'jpoptistory', 'external_key' => 'jpt-1', 'kind' => 'concert', 'genre' => 'jpop', 'title' => 'Reol Oneman Live 2026 in SEOUL', 'starts_on' => '2026-07-18']);

        $stats = app(EventSyncService::class)->sync([
            new CollectedEventData(source: 'lounge', externalKey: 'lg-1', kind: EventKind::Concert,
                title: 'Reol 내한공연', startsOn: '2026-07-18',
                ticketLinks: [['label' => '예매하기', 'url' => 'https://t.example/reol']]),
            new CollectedEventData(source: 'lounge', externalKey: 'lg-2', kind: EventKind::Concert,
                title: 'YUINA Fan Meeting', startsOn: '2026-07-18'),
        ]);

        $this->assertSame(1, $stats['created'], '같은 날 다른 아티스트(YUINA)만 생성');
        $this->assertSame(1, $stats['skipped'], 'Reol 은 기존 행과 중복 — 스킵');
        $this->assertSame(1, Event::where('title', 'like', '%Reol%')->count(), '중복 행 없음');
        $dup = Event::where('source', 'jpoptistory')->first();
        $this->assertSame('https://t.example/reol', $dup->ticket_links[0]['url'], '빈 예매 링크는 보강');
        $this->assertSame('jpop', $dup->genre, '기존 행 속성 유지');
    }

    public function test_same_source_resync_updates_in_place(): void
    {
        Event::create(['source' => 'jpoptistory', 'external_key' => 'jpt-1', 'kind' => 'concert', 'title' => 'Reol Oneman Live', 'starts_on' => '2026-07-18']);

        $stats = app(EventSyncService::class)->sync([
            new CollectedEventData(source: 'jpoptistory', externalKey: 'jpt-1', kind: EventKind::Concert,
                title: 'Reol Oneman Live', startsOn: '2026-07-18', venue: '원더로크홀'),
        ]);

        $this->assertSame(1, $stats['updated'], '같은 (source, key) 재수집은 중복 방지에 안 걸리고 갱신(멱등)');
        $this->assertSame('원더로크홀', Event::first()->venue);
    }
}
