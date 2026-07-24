<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\CoexDriver;
use App\Services\EventCalendar\Sources\KintexDriver;
use App\Services\EventCalendar\Sources\SetecDriver;
use App\Services\EventCalendar\Sources\VenueEventFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VenueDriversTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['event-calendar.venues.delay_ms' => 0]);
    }

    /** 실측 마크업 축약 픽스처 — 킨텍스 grid-frame-cell 구조. */
    private function kintexList(): string
    {
        return <<<'HTML'
            <div class="thumb-board-list schedule-board-list">
            <div class="grid-frame-cell grid-03"><a class="btn-square-item green" href="javascript:fnView('./view.do', 111);">
              <div class="ko-txt">문화행사<br />Culture</div>
              <p class="item-subject">블루 아카이브 5주년 페스티벌</p>
              <p class="item-client">전시홀 1 , 전시홀 2</p>
              <p class="item-date">2026.11.28~2026.11.29</p></a></div>
            <div class="grid-frame-cell grid-03"><a class="btn-square-item red" href="javascript:fnView('./view.do', 222);">
              <div class="ko-txt">전시회<br />EXHIBITION</div>
              <p class="item-subject">2026 금속산업대전</p>
              <p class="item-client">전시홀 3</p>
              <p class="item-date">2026.09.01~2026.09.03</p></a></div>
            <div class="grid-frame-cell grid-03"><a class="btn-square-item green" href="javascript:fnView('./view.do', 333);">
              <div class="ko-txt">문화행사<br />Culture</div>
              <p class="item-subject">워터밤 서울 2027</p>
              <p class="item-client">옥외</p>
              <p class="item-date">2027.07.24~2027.07.26</p></a></div>
            </div>
            HTML;
    }

    public function test_kintex_collects_subculture_culture_events_only(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'view.do?seq=111')) {
                // 상세: 공백 낀 라벨(실측 형식)
                return Http::response('<td>주 최</td><td>넥슨게임즈</td><td>입 장 료</td><td>50,000원</td><td>관 람 시 간</td><td>10:00~18:00</td>');
            }
            if (str_contains($url, 'view.do?seq=333')) {
                return Http::response('<td>주 최</td><td>㈜메이드온</td>'); // 서브컬쳐 아님
            }
            if (str_contains($url, 'pageIndex=1')) {
                return Http::response($this->kintexList());
            }

            return Http::response('<div>empty</div>'); // 이후 페이지
        });

        $events = app(KintexDriver::class)->collect();

        $this->assertCount(1, $events, '블아 페스티벌만(산업전시·비서브컬쳐 문화행사 제외)');
        $e = $events[0];
        $this->assertSame('kintex-111', $e->externalKey);
        $this->assertSame(EventKind::Expo, $e->kind);
        $this->assertSame('2026-11-28', $e->startsOn);
        $this->assertSame('2026-11-29', $e->endsOn);
        $this->assertSame('킨텍스 전시홀 1 , 전시홀 2', $e->venue);
        $this->assertSame('넥슨게임즈', $e->extra['host']);
        $this->assertSame('50,000원', $e->priceText);
        $this->assertSame('10:00~18:00', $e->timeText);
    }

    public function test_setec_filters_by_keyword(): void
    {
        $list = <<<'HTML'
            <div class="exhibit_list"><ul>
            <li><a href="#" onclick="fn_view('2299'); return false;"><div class="img"><img src="/file/viewImg.do?fIdx=3706" alt="x"></div>
              <div class="txt"><strong>디.페스타 2026 하반기</strong><ul><li>기간 : 2026-08-25 ~ 2026-08-26</li><li>장소 : 제1전시실</li></ul></div></a></li>
            <li><a href="#" onclick="fn_view('2300'); return false;"><div class="txt"><strong>코리아보드게임즈</strong><ul><li>기간 : 2026-07-25 ~ 2026-07-26</li><li>장소 : 제3전시실</li></ul></div></a></li>
            </ul></div>
            HTML;
        Http::fake(function ($request) use ($list) {
            return Http::response(str_contains($request->url(), 'pageIndex=1') ? $list : '<div>empty</div>');
        });

        $events = app(SetecDriver::class)->collect();

        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame('setec-2299', $e->externalKey);
        $this->assertSame(EventKind::Doujin, $e->kind, '디페스타는 동인 행사');
        $this->assertSame('SETEC 제1전시실', $e->venue);
        $this->assertStringContainsString('viewImg.do?fIdx=3706', $e->posterUrl);
    }

    public function test_coex_filters_and_excludes_dedupe(): void
    {
        $list = <<<'HTML'
            <div class='BlogEventItem'><a href="https://www.coex.co.kr/exhibitions/2026-%EC%84%9C%EC%9A%B8-%ED%8C%9D%EC%BD%98/?var_page=1"><span>Pop-up/Event</span>
              <b>서울팝콘 2026</b><i>2026.09.11 - 2026.09.13</i><em>Hall A</em></a></div>
            <div class='BlogEventItem'><a href="https://www.coex.co.kr/exhibitions/k-display-2026/?var_page=1"><span>Exhibition</span>
              <b>K-Display 2026 한국디스플레이산업전시회</b><i>2026.07.22 - 2026.07.24</i><em>Hall C</em></a></div>
            <div class='BlogEventItem'><a href="https://www.coex.co.kr/exhibitions/comicworld-x/?var_page=1"><span>Event</span>
              <b>코믹월드 스페셜</b><i>2026.10.01 - 2026.10.02</i><em>Hall B</em></a></div>
            HTML;
        Http::fake(function ($request) use ($list) {
            return Http::response(str_contains($request->url(), 'var_page=1') ? $list : '<div>empty</div>');
        });

        $events = app(CoexDriver::class)->collect();

        $this->assertCount(1, $events, '팝콘만(산업전시 제외, 코믹월드는 전용 소스 중복 방지)');
        $e = $events[0];
        $this->assertSame('coex-2026-서울-팝콘', $e->externalKey);
        $this->assertSame('2026-09-11', $e->startsOn);
        $this->assertSame('코엑스 Hall A', $e->venue);
    }

    public function test_filter_kind_mapping(): void
    {
        $filter = app(VenueEventFilter::class);

        $this->assertSame(EventKind::Concert, $filter->kindFor('결속밴드 라이브 인 코리아'));
        $this->assertSame(EventKind::Doujin, $filter->kindFor('만화창작 온리전'));
        $this->assertSame(EventKind::Expo, $filter->kindFor('블루 아카이브 5주년 페스티벌'));
        $this->assertTrue($filter->isDedupe('코믹월드 SUMMER 2026'));
        $this->assertTrue($filter->isSubculture('무명 행사', '주식회사 동인네트워크'), '주최 화이트리스트');
    }
}
