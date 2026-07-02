<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaCommunityDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ArcaCommunityDriver 화이트박스: 니케(nikketgv)는 쿠폰 카테고리의 '최근 N일' 글 제목에서
 * 코드를 수집(1차에 없던 신규 유입). 오래된 글은 제외, 띄어쓰기 코드는 이어붙여 수집.
 */
class ArcaCommunityDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 테스트 독립성: 니케 아카 설정을 명시적으로 고정.
        config()->set('subculture-game-info.drivers.arca.base', 'https://arca.live/b/');
        config()->set('subculture-game-info.drivers.arca.channels.nikke', 'nikketgv');
        config()->set('subculture-game-info.drivers.arca.categories.nikke', '쿠폰');
        config()->set('subculture-game-info.drivers.arca.recent_days', 7);
        config()->set('subculture-game-info.games.nikke.region_default', 'kr');
    }

    /** 아카 목록 글 행(a.vrow.column) 마크업 한 개 생성. */
    private function row(string $title, string $datetime): string
    {
        return <<<HTML
        <a class="vrow column" href="/b/nikketgv/1">
          <div class="col-title"><span class="title">{$title}</span></div>
          <time datetime="{$datetime}">표시</time>
        </a>
        HTML;
    }

    private function fakeCouponList(string $rowsHtml): void
    {
        Http::fake([
            'arca.live/b/nikketgv*' => Http::response("<html><body>{$rowsHtml}</body></html>", 200),
        ]);
    }

    public function test_collects_recent_coupon_codes_and_skips_old(): void
    {
        Carbon::setTestNow('2026-06-25 10:00:00');
        $this->fakeCouponList(
            $this->row('신규 쿠폰 UNBREAKABLEMEMORIES 등록하세요', '2026-06-24T09:00:00+09:00').
            $this->row('옛날 쿠폰 OLDCODE1234', '2026-06-10T09:00:00+09:00') // 7일 초과 → 제외
        );

        $dtos = (new ArcaCommunityDriver)->collect('nikke', []);
        $codes = array_map(fn ($d) => $d->code, $dtos);

        $this->assertContains('UNBREAKABLEMEMORIES', $codes);
        $this->assertNotContains('OLDCODE1234', $codes); // 최근 7일 밖
        $this->assertSame(SourceType::Community, $dtos[0]->sourceType);
        $this->assertSame('arca', $dtos[0]->source);

        Carbon::setTestNow();
    }

    public function test_joins_spaced_code_in_title(): void
    {
        // 소스에 띄어쓰기로 표기된 코드는 이어붙여 수집(조각 제외).
        Carbon::setTestNow('2026-06-25 10:00:00');
        $this->fakeCouponList(
            $this->row('쿠폰 UNBREAKABLE MEMORIES 배포', '2026-06-24T09:00:00+09:00')
        );

        $codes = array_map(fn ($d) => $d->code, (new ArcaCommunityDriver)->collect('nikke', []));

        $this->assertContains('UNBREAKABLEMEMORIES', $codes);
        $this->assertNotContains('UNBREAKABLE', $codes);
        $this->assertNotContains('MEMORIES', $codes);

        Carbon::setTestNow();
    }

    public function test_skips_rows_without_date(): void
    {
        // 작성일을 못 읽으면 보수적으로 제외(최근성 보장 불가).
        Carbon::setTestNow('2026-06-25 10:00:00');
        Http::fake([
            'arca.live/b/nikketgv*' => Http::response(
                '<html><body><a class="vrow column" href="/b/nikketgv/1">'.
                '<div class="col-title"><span class="title">쿠폰 NODATECODE9</span></div></a></body></html>',
                200
            ),
        ]);

        $this->assertSame([], (new ArcaCommunityDriver)->collect('nikke', []));
        Carbon::setTestNow();
    }
}
