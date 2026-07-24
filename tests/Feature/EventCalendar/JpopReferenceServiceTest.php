<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\JpopReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 블로그 캘린더는 이벤트 소스가 아니라 J-pop 장르 판별 레퍼런스 — 달력 행을 만들지 않고
 * festivallife 공연과 날짜·아티스트 토큰 대조로 genre 만 확정한다.
 */
class JpopReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSidecar(array $items): void
    {
        Process::fake(['*' => Process::result(json_encode(['source' => 'jpoptistory', 'items' => $items], JSON_UNESCAPED_UNICODE))]);
    }

    public function test_tags_matching_concerts_without_creating_events(): void
    {
        // 미태깅(null) + Gemini 오분류(other) + 무관 공연 + 이미 jpop
        Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'title' => 'Reol 내한공연', 'starts_on' => '2026-08-18']);
        Event::create(['source' => 'festivallife', 'external_key' => '2', 'kind' => 'concert', 'genre' => 'other', 'title' => 'YUINA 단독 콘서트', 'starts_on' => '2026-08-20', 'ends_on' => '2026-08-21']);
        Event::create(['source' => 'festivallife', 'external_key' => '3', 'kind' => 'concert', 'genre' => 'other', 'title' => 'Deep Purple 내한공연', 'starts_on' => '2026-08-18']);
        Event::create(['source' => 'festivallife', 'external_key' => '4', 'kind' => 'concert', 'genre' => 'jpop', 'title' => 'YOASOBI 내한공연', 'starts_on' => '2026-09-01']);

        $this->fakeSidecar([
            ['date' => '2026-08-18', 'title' => 'Reol Oneman Live in SEOUL', 'location' => '원더로크홀', 'link' => '', 'category' => 'concert'],
            ['date' => '2026-08-21', 'title' => 'YUINA Fan Meeting', 'location' => '성암아트홀', 'link' => '', 'category' => 'fanmeeting'], // 기간(20~21) 안 날짜 매칭
            ['date' => '2026-09-05', 'title' => 'Reol Oneman Live in SEOUL', 'location' => '고척돔', 'link' => '', 'category' => 'concert'], // 날짜 불일치 — 다른 회차
        ]);

        $stats = app(JpopReferenceService::class)->tagFromReference();

        $this->assertSame(['matched' => 2, 'refs' => 3, 'skipped' => false], $stats);
        $this->assertSame(4, Event::count(), '레퍼런스는 달력 행을 만들지 않는다');
        $genre = fn (string $key) => Event::where('external_key', $key)->first()->genre;
        $this->assertSame('jpop', $genre('1'), '미태깅 → jpop 확정');
        $this->assertSame('jpop', $genre('2'), 'Gemini 오분류(other)도 블로그 근거로 정정');
        $this->assertSame('other', $genre('3'), '블로그에 없는 공연은 그대로');
    }

    public function test_sidecar_failure_is_graceful(): void
    {
        Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'title' => 'Reol 내한공연', 'starts_on' => '2026-08-18']);
        Process::fake(['*' => Process::result('', '사이드카 죽음', 1)]);

        $stats = app(JpopReferenceService::class)->tagFromReference();

        $this->assertTrue($stats['skipped']);
        $this->assertNull(Event::first()->genre, '실패 시 아무것도 안 바꿈');
    }
}
