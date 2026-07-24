<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\ComicWorldDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComicWorldDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_collects_comic_and_mongu_events_from_json_api(): void
    {
        // 정찰 실측 스키마 그대로의 응답(코믹 2건 리스트 / 문구전 1건 — submitLink 없음)
        Http::fake([
            'comicw.net/*' => Http::sequence()
                ->push([
                    [
                        'title' => '코믹월드 335 일산', 'place' => '일산 킨텍스 제1전시장', 'tag' => '8코일산',
                        'status' => '0', 'startDate' => '2026-08-15', 'endDate' => '2026-08-16',
                        'submitLink' => 'https://comicw.net/e/335',
                        'ticketLink' => 'https://comicw.net/shop/item.php?it_id=1775781350',
                        'guideLink' => 'https://comicw.net/e/335/8',
                    ],
                    [
                        'title' => '코믹월드 336 부산', 'place' => '벡스코', 'status' => '2',
                        'startDate' => '2026-10-03', 'endDate' => '2026-10-03',
                        'submitLink' => 'https://comicw.net/e/336', 'ticketLink' => '', 'guideLink' => '',
                    ],
                ])
                ->push([
                    ['title' => '문구전 12', 'place' => 'SETEC', 'startDate' => '2026-09-05', 'endDate' => '2026-09-06', 'submitLink' => ''],
                ]),
        ]);

        $events = app(ComicWorldDriver::class)->collect();

        $this->assertCount(3, $events);
        $first = $events[0];
        $this->assertSame('comicworld', $first->source);
        $this->assertSame('comic-335', $first->externalKey, '회차번호가 external_key');
        $this->assertSame(EventKind::Doujin, $first->kind);
        $this->assertSame('2026-08-15', $first->startsOn);
        $this->assertSame('2026-08-16', $first->endsOn);
        $this->assertSame('일산 킨텍스 제1전시장', $first->venue);
        $this->assertSame('티켓 구매', $first->ticketLinks[0]['label']);

        $this->assertNull($events[1]->endsOn, '당일 행사는 ends_on null');
        $this->assertStringStartsWith('mongu-', $events[2]->externalKey, '문구전은 제목 폴백 키');
    }

    public function test_api_failure_returns_empty_without_throwing(): void
    {
        Http::fake(['comicw.net/*' => Http::response(null, 500)]);

        $this->assertSame([], app(ComicWorldDriver::class)->collect());
    }
}
