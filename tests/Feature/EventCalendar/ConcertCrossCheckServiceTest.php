<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\ConcertCrossCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 내한공연 크로스체크 — 가수명 → MusicBrainz(X 핸들) → 가수 타임라인(nitter) 대조,
 * 신호 없으면 구글 뉴스 RSS 폴백. mycon 확보값과 다르면 덮지 않고 불일치 기록.
 */
class ConcertCrossCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_name_heuristic(): void
    {
        $cases = [
            'Chilli Beans. Asia Tour 2026 in Seoul' => 'Chilli Beans.',
            '오피셜히게단디즘 아시아 투어 2026 in SEOUL' => '오피셜히게단디즘',
            'Vaundy ASIA ARENA TOUR 2026 “HORO” IN SEOUL' => 'Vaundy',
            'YUURI LIVE 2026 IN SEOUL' => 'YUURI',
            '2026 카즈미 타테이시 트리오 내한공연 - 크리스마스, 재즈를 만나다 (서울)' => '카즈미 타테이시 트리오',
            '무쿠 내한공연(muque LIVE TOUR 2026 “GLHF” in Seoul)' => '무쿠',
            '&TEAM CONCERT TOUR ‘BLAZE THE WAY’ in INCHEON' => '&TEAM',
        ];
        foreach ($cases as $title => $expected) {
            $this->assertSame($expected, ConcertCrossCheckService::artistName($title), $title);
        }
    }

    private function makeConcert(array $attrs = []): Event
    {
        return Event::create(array_merge([
            'source' => 'jpoptistory', 'external_key' => 'jpt-1', 'kind' => 'concert', 'genre' => 'jpop',
            'title' => 'Vaundy ASIA ARENA TOUR 2026 “HORO” IN SEOUL', 'starts_on' => '2026-09-19', 'ends_on' => '2026-09-20',
        ], $attrs));
    }

    /** MusicBrainz(검색+상세) + 가수 타임라인 RSS + 뉴스 RSS 를 한 번에 fake. */
    private function fakeSources(array $tweets = [], array $news = [], bool $withHandle = true): void
    {
        $rss = fn (array $posts) => '<rss><channel>'.collect($posts)->map(fn ($t) => '<item><title>'.htmlspecialchars($t['text']).'</title>'
            .'<pubDate>'.($t['pubDate'] ?? 'Tue, 28 Jul 2026 09:00:00 GMT').'</pubDate>'
            .'<link>'.($t['link'] ?? 'https://x.com/Vaundy_AWS/status/1').'</link></item>')->implode('').'</channel></rss>';

        Http::fake([
            'musicbrainz.org/ws/2/artist/?*' => Http::response([
                'artists' => $withHandle ? [['id' => 'mb-1', 'name' => 'Vaundy', 'score' => 100]] : [],
            ]),
            'musicbrainz.org/ws/2/artist/mb-1*' => Http::response([
                'relations' => [['url' => ['resource' => 'https://twitter.com/Vaundy_AWS']]],
            ]),
            '*/Vaundy_AWS/rss' => Http::response($rss($tweets)),
            'news.google.com/*' => Http::response($rss($news)),
        ]);
    }

    public function test_artist_tweet_fills_open_date_and_confirms_schedule(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert();
        $this->fakeSources(tweets: [
            ['text' => '【ソウル公演】Vaundy ASIA ARENA TOUR ソウル 9/19(土)・9/20(日) チケット発売：8/11(火) 20:00'],
            ['text' => '日本ツアー追加公演のお知らせ 10/3 大阪'], // 내한 신호 없음 — 무시돼야 함
        ]);

        $stats = app(ConcertCrossCheckService::class)->run();

        $this->assertSame(1, $stats['handles']);
        $this->assertSame(1, $stats['filled']);
        $this->assertSame(1, $stats['schedule_ok']);
        $event->refresh();
        $this->assertSame('2026-08-11', $event->ticket_opens_on->toDateString(), '가수 트윗(일본식 날짜)에서 오픈일 보강');
        $this->assertSame('x', $event->extra['ticket_open_source']);
        $this->assertSame('ok', $event->extra['xcheck_schedule'], '공연 기간 내 날짜 언급 → 내한 일정 확인');
        $this->assertSame('Vaundy_AWS', $event->extra['x_handle'], '핸들은 행에 기록(재조회 방지)');
    }

    public function test_mismatch_with_mycon_value_is_recorded_not_overwritten(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert(['ticket_opens_on' => '2026-08-10']); // mycon 확보값
        $this->fakeSources(tweets: [['text' => 'ソウル공연 티켓 오픈: 8월 11일 (화) 오후 8시']]);

        $stats = app(ConcertCrossCheckService::class)->run();

        $this->assertSame(1, $stats['mismatched']);
        $event->refresh();
        $this->assertSame('2026-08-10', $event->ticket_opens_on->toDateString(), 'mycon 구조화 데이터 우선');
        $this->assertSame('mismatch', $event->extra['xcheck']);
        $this->assertSame('2026-08-11', $event->extra['xcheck_open']);
    }

    public function test_news_fallback_when_no_x_signal(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert();
        // 타임라인엔 내한 신호 없음 → 뉴스 폴백. 기사 제목의 9월 19일이 일정 확인
        $this->fakeSources(
            tweets: [['text' => '新曲リリースのお知らせ']],
            news: [['text' => "'J-팝' 바운디, 9월 19일 첫 내한공연…티켓 오픈 8월 11일 - 뉴시스", 'link' => 'https://news.example/1']],
        );

        $stats = app(ConcertCrossCheckService::class)->run();

        $this->assertGreaterThanOrEqual(1, $stats['articles']);
        $event->refresh();
        $this->assertSame('2026-08-11', $event->ticket_opens_on->toDateString(), '뉴스에서 오픈일 보강');
        $this->assertSame('news', $event->extra['ticket_open_source']);
        $this->assertSame('ok', $event->extra['xcheck_schedule']);
        $this->assertSame('news', $event->extra['xcheck_schedule_source']);
    }

    public function test_no_handle_and_no_news_is_harmless(): void
    {
        $this->travelTo('2026-07-28');
        $event = $this->makeConcert();
        $this->fakeSources(withHandle: false, news: []);

        $stats = app(ConcertCrossCheckService::class)->run();

        $this->assertSame(0, $stats['handles']);
        $this->assertNull($event->fresh()->ticket_opens_on);
        $this->assertSame('', $event->fresh()->extra['x_handle'], '못 찾은 핸들도 기록(재조회 방지)');
    }
}
