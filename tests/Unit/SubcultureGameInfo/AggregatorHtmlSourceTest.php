<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\Sources\AggregatorHtmlSource;
use Tests\TestCase;

/**
 * 정리 사이트 HTML 에서 코드 토큰 추출(extractCodes) 단위 테스트.
 * 강조/표 요소(<b>,<td>,<strong> 등)의 텍스트에서만 코드형 토큰을 뽑고
 * denylist(GIFT, CODE 등)·소문자 산문은 걸러야 한다.
 */
class AggregatorHtmlSourceTest extends TestCase
{
    private AggregatorHtmlSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new AggregatorHtmlSource;
    }

    public function test_extracts_code_tokens_from_emphasized_and_table_cells(): void
    {
        $html = <<<'HTML'
            <div>
                <b>WUTHERINGGIFT</b>
                <td>ABCD1234</td>
                <p>this is just some prose with http links and stuff</p>
            </div>
        HTML;

        $codes = $this->source->extractCodes($html);

        $this->assertContains('WUTHERINGGIFT', $codes);
        $this->assertContains('ABCD1234', $codes);
    }

    public function test_filters_denylist_tokens(): void
    {
        // 'GIFT' 단독, 'CODE' 등 denylist 토큰은 코드로 인정하지 않는다.
        $html = '<b>GIFT</b><strong>CODE</strong><td>REDEEM</td>';

        $codes = $this->source->extractCodes($html);

        $this->assertNotContains('GIFT', $codes);
        $this->assertNotContains('CODE', $codes);
        $this->assertNotContains('REDEEM', $codes);
    }

    public function test_filters_lowercase_prose_tokens(): void
    {
        // 소문자 위주 산문 토큰은 코드로 보지 않는다.
        $html = '<strong>welcome</strong><b>hello</b>';

        $codes = $this->source->extractCodes($html);

        $this->assertNotContains('welcome', $codes);
        $this->assertNotContains('hello', $codes);
        $this->assertSame([], $codes);
    }

    public function test_deduplicates_codes_case_insensitively(): void
    {
        $html = '<b>STARRAILGIFT</b><td>STARRAILGIFT</td>';

        $codes = $this->source->extractCodes($html);

        $this->assertCount(1, $codes);
        $this->assertSame(['STARRAILGIFT'], $codes);
    }
}
