<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\EventSyncService;
use App\Services\EventCalendar\Sources\JpopTistoryDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class JpopTistoryDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_consecutive_dates_and_keeps_separate_runs(): void
    {
        Process::fake(['*' => Process::result(json_encode(['source' => 'jpoptistory', 'items' => [
            ['date' => '2026-07-04', 'title' => '&TEAM CONCERT TOUR', 'location' => '인스파이어 아레나', 'link' => 'https://t.example/1', 'category' => 'concert'],
            ['date' => '2026-07-05', 'title' => '&TEAM CONCERT TOUR', 'location' => '인스파이어 아레나', 'link' => 'https://t.example/1', 'category' => 'concert'],
            ['date' => '2026-09-12', 'title' => '&TEAM CONCERT TOUR', 'location' => '고척돔', 'link' => 'https://t.example/1', 'category' => 'concert'], // 비연속 — 별도 회차
            ['date' => '2026-07-18', 'title' => 'YUINA Fan Meeting in Seoul', 'location' => '성암아트홀', 'link' => '', 'category' => 'fanmeeting'],
        ]], JSON_UNESCAPED_UNICODE))]);

        $events = app(JpopTistoryDriver::class)->collect();

        $this->assertCount(3, $events, '연속 이틀은 1건 + 비연속 회차 1건 + 팬미팅 1건');
        $team = $events[0];
        $this->assertSame('2026-07-04', $team->startsOn);
        $this->assertSame('2026-07-05', $team->endsOn, '연속 날짜는 기간으로');
        $this->assertSame(EventKind::Concert, $team->kind);
        $this->assertSame('jpop', $team->genre, '큐레이션 소스 — 장르 확정');
        $this->assertSame('예매하기', $team->ticketLinks[0]['label']);

        $this->assertSame('2026-09-12', $events[1]->startsOn, '비연속 날짜는 별도 회차');
        $this->assertNull($events[1]->endsOn);
        $this->assertSame('fanmeeting', $events[2]->extra['category']);
    }

    public function test_cross_source_concert_dedupe_in_sync(): void
    {
        // festivallife 에 이미 있는 공연(같은 날·같은 아티스트)은 큐레이션 소스에서 스킵
        Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'title' => 'Reol 내한공연', 'starts_on' => '2026-07-18']);

        Process::fake(['*' => Process::result(json_encode(['source' => 'jpoptistory', 'items' => [
            ['date' => '2026-07-18', 'title' => 'Reol Oneman Live 2026 in SEOUL', 'location' => '원더로크홀', 'link' => '', 'category' => 'concert'],
            ['date' => '2026-07-18', 'title' => 'YUINA Fan Meeting', 'location' => '성암아트홀', 'link' => '', 'category' => 'fanmeeting'],
        ]], JSON_UNESCAPED_UNICODE))]);

        $stats = app(EventSyncService::class)->sync(app(JpopTistoryDriver::class)->collect());

        $this->assertSame(1, $stats['created'], '같은 날 다른 아티스트(YUINA)만 생성');
        $this->assertSame(1, $stats['skipped'], 'Reol 은 festivallife 와 중복 — 스킵');
        $this->assertSame(0, Event::where('source', 'jpoptistory')->where('title', 'like', '%Reol%')->count());

        // 재수집 시 기존 jpoptistory 행 갱신은 중복 방지에 안 걸림(멱등)
        $stats2 = app(EventSyncService::class)->sync(app(JpopTistoryDriver::class)->collect());
        $this->assertSame(1, $stats2['updated']);
    }
}
