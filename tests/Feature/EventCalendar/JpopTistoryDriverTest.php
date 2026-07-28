<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\JpopTistoryDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class JpopTistoryDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_consecutive_dates_and_carries_mycon_link(): void
    {
        Process::fake(['*' => Process::result(json_encode(['source' => 'jpoptistory', 'items' => [
            ['date' => '2026-07-04', 'title' => '&TEAM CONCERT TOUR', 'location' => '인스파이어 아레나', 'link' => 'https://mycon.me/concert/1048', 'category' => 'concert'],
            ['date' => '2026-07-05', 'title' => '&TEAM CONCERT TOUR', 'location' => '인스파이어 아레나', 'link' => 'https://mycon.me/concert/1048', 'category' => 'concert'],
            ['date' => '2026-09-12', 'title' => '&TEAM CONCERT TOUR', 'location' => '고척돔', 'link' => 'https://mycon.me/concert/1048', 'category' => 'concert'], // 비연속 — 별도 회차
            ['date' => '2026-07-18', 'title' => 'YUINA Fan Meeting in Seoul', 'location' => '성암아트홀', 'link' => '', 'category' => 'fanmeeting'],
        ]], JSON_UNESCAPED_UNICODE))]);

        $events = app(JpopTistoryDriver::class)->collect();

        $this->assertCount(3, $events, '연속 이틀은 1건 + 비연속 회차 1건 + 팬미팅 1건');
        $team = $events[0];
        $this->assertSame('2026-07-04', $team->startsOn);
        $this->assertSame('2026-07-05', $team->endsOn, '연속 날짜는 기간으로');
        $this->assertSame(EventKind::Concert, $team->kind);
        $this->assertSame('jpop', $team->genre, '큐레이션 소스 — 장르 확정');
        $this->assertSame('https://mycon.me/concert/1048', $team->extra['mycon_url'], 'mycon 링크는 예매일 보강 계약으로 전달');
        $this->assertSame('https://mycon.me/concert/1048', $team->detailUrl, '상세보기는 mycon 상세로');

        $this->assertSame('2026-09-12', $events[1]->startsOn, '비연속 날짜는 별도 회차');
        $this->assertNull($events[1]->endsOn);
        // mycon 링크가 없는 항목은 블로그가 상세이고 mycon 계약 없음
        $this->assertSame('fanmeeting', $events[2]->extra['category']);
        $this->assertArrayNotHasKey('mycon_url', $events[2]->extra);
        $this->assertStringContainsString('tistory.com', $events[2]->detailUrl);
    }

    public function test_sidecar_failure_returns_empty(): void
    {
        Process::fake(['*' => Process::result('', '사이드카 죽음', 1)]);

        $this->assertSame([], app(JpopTistoryDriver::class)->collect());
    }
}
