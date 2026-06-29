<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\HtmlDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HtmlDriver 화이트박스: parseRows / scanTokens / collect(union) 의 분기·경계.
 */
class HtmlDriverTest extends TestCase
{
    private HtmlDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new HtmlDriver;
    }

    /** parseRows 결과를 코드(대문자) => row 로 인덱싱. */
    private function rowsByCode(string $html): array
    {
        $out = [];
        foreach ($this->driver->parseRows($html) as $row) {
            $out[strtoupper($row['code'])] = $row;
        }

        return $out;
    }

    // ---------------------------------------------------------------- parseRows
    public function test_parse_rows_future_expiry_is_active(): void
    {
        $future = now()->addDays(30)->format('Y-m-d');
        $html = <<<HTML
        <table><tbody>
          <tr><th>Code</th><th>Reward</th><th>Expiry</th></tr>
          <tr><td>GENSHINGIFT</td><td>원석 60개 + 모라 10000</td><td>{$future}</td></tr>
        </tbody></table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertArrayHasKey('GENSHINGIFT', $rows);
        $this->assertSame(CodeStatus::Active, $rows['GENSHINGIFT']['status']);
        // 보상 셀: 가장 긴 텍스트 선택
        $this->assertSame('원석 60개 + 모라 10000', $rows['GENSHINGIFT']['rewards']);
        $this->assertNotNull($rows['GENSHINGIFT']['expiresAt']);
    }

    public function test_parse_rows_past_expiry_is_expired(): void
    {
        $past = now()->subDays(5)->format('Y-m-d');
        $html = <<<HTML
        <table><tr><td>OLDCODE123</td><td>지난 보상</td><td>{$past}</td></tr></table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertSame(CodeStatus::Expired, $rows['OLDCODE123']['status']);
    }

    public function test_parse_rows_expired_hint_text_marks_expired(): void
    {
        // 만료일 없이 '만료' 힌트만 있어도 expired
        $html = <<<'HTML'
        <table><tr><td>DEADCODE99</td><td>보상 설명 텍스트</td><td>만료됨</td></tr></table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertSame(CodeStatus::Expired, $rows['DEADCODE99']['status']);
    }

    public function test_parse_rows_english_expired_hint(): void
    {
        $html = <<<'HTML'
        <table><tr><td>GONECODE88</td><td>some reward</td><td>expired</td></tr></table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertSame(CodeStatus::Expired, $rows['GONECODE88']['status']);
    }

    public function test_parse_rows_no_expiry_no_hint_is_unverified(): void
    {
        $html = <<<'HTML'
        <table><tr><td>MYSTERY777</td><td>알수없는 보상 설명</td></tr></table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertSame(CodeStatus::Unverified, $rows['MYSTERY777']['status']);
    }

    public function test_parse_rows_skips_rows_without_code(): void
    {
        $future = now()->addDays(10)->format('Y-m-d');
        $html = <<<HTML
        <table>
          <tr><td>그냥 헤더 설명</td><td>코드 없음</td></tr>
          <tr><td>VALIDCODE10</td><td>보상</td><td>{$future}</td></tr>
        </table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('VALIDCODE10', $rows);
    }

    public function test_parse_rows_reward_picks_longest_cell(): void
    {
        $future = now()->addDays(10)->format('Y-m-d');
        $html = <<<HTML
        <table><tr>
          <td>CODECODE12</td>
          <td>짧음</td>
          <td>이것이 가장 긴 보상 설명 셀입니다 원석 모라 등등</td>
          <td>{$future}</td>
        </tr></table>
        HTML;

        $rows = $this->rowsByCode($html);
        $this->assertSame('이것이 가장 긴 보상 설명 셀입니다 원석 모라 등등', $rows['CODECODE12']['rewards']);
    }

    // ---------------------------------------------------------------- scanTokens
    public function test_scan_tokens_extracts_from_emphasis_elements(): void
    {
        $html = <<<'HTML'
        <div>
          <strong>GENSHINGIFT</strong>
          <b>ZONEFEVER2024</b>
          <code>STARRAILGIFT</code>
          <p>this is lowercase prose ignored</p>
        </div>
        HTML;

        $tokens = $this->driver->scanTokens($html);
        $this->assertContains('GENSHINGIFT', $tokens);
        $this->assertContains('ZONEFEVER2024', $tokens);
        $this->assertContains('STARRAILGIFT', $tokens);
    }

    public function test_scan_tokens_excludes_denylist_and_prose(): void
    {
        $html = <<<'HTML'
        <div>
          <strong>EXPIRED</strong>
          <strong>this is lowercase prose</strong>
          <strong>REALCODE42</strong>
        </div>
        HTML;

        $tokens = $this->driver->scanTokens($html);
        $this->assertNotContains('EXPIRED', $tokens);
        $this->assertNotContains('this is lowercase prose', $tokens);
        $this->assertContains('REALCODE42', $tokens);
    }

    public function test_scan_tokens_respects_limit_of_25(): void
    {
        // 26개 이상의 서로 다른 코드형 토큰을 강조 요소로 제공
        $items = '';
        for ($i = 1; $i <= 30; $i++) {
            $items .= '<strong>CODEXX'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).'</strong>';
        }
        $html = "<div>{$items}</div>";

        $tokens = $this->driver->scanTokens($html);
        $this->assertCount(25, $tokens, 'TOKEN_SCAN_LIMIT(25)에서 멈춰야 함');
    }

    // ---------------------------------------------------------------- collect (union)
    public function test_collect_unions_table_rows_and_token_scan(): void
    {
        $future = now()->addDays(20)->format('Y-m-d');
        // 표에는 TABLECODE1, 토큰 스캔에만 잡히는 강조 요소에는 TOKENONLY9
        $html = <<<HTML
        <html><body>
          <table><tr><td>TABLECODE1</td><td>표 보상 설명</td><td>{$future}</td></tr></table>
          <strong>TOKENONLY9</strong>
        </body></html>
        HTML;

        Http::fake(['https://example.test/*' => Http::response($html, 200)]);

        $dtos = $this->driver->collect('genshin', ['url' => 'https://example.test/codes']);
        $byCode = [];
        foreach ($dtos as $d) {
            $byCode[strtoupper($d->code)] = $d;
        }

        // 표 코드: 풍부한 정보 유지(active, 보상, sourceType aggregator)
        $this->assertArrayHasKey('TABLECODE1', $byCode);
        $this->assertSame(CodeStatus::Active, $byCode['TABLECODE1']->status);
        $this->assertSame('표 보상 설명', $byCode['TABLECODE1']->rewards);

        // 토큰 보강 코드: 미검증, aggregator, source = hostLabel
        $this->assertArrayHasKey('TOKENONLY9', $byCode);
        $this->assertSame(CodeStatus::Unverified, $byCode['TOKENONLY9']->status);
        $this->assertSame(SourceType::Aggregator, $byCode['TOKENONLY9']->sourceType);
        $this->assertSame('example', $byCode['TOKENONLY9']->source);
    }

    public function test_collect_table_code_not_overwritten_by_token_scan(): void
    {
        // 표와 토큰 스캔 모두 같은 코드를 보고 → 표의 풍부한 정보가 유지되어야
        $future = now()->addDays(15)->format('Y-m-d');
        $html = <<<HTML
        <html><body>
          <table><tr><td>SHARED123X</td><td>표에서 온 보상</td><td>{$future}</td></tr></table>
          <strong>SHARED123X</strong>
        </body></html>
        HTML;

        Http::fake(['*' => Http::response($html, 200)]);

        $dtos = $this->driver->collect('genshin', ['url' => 'https://example.test/codes']);
        $shared = collect($dtos)->firstWhere('code', 'SHARED123X');

        $this->assertNotNull($shared);
        $this->assertSame(CodeStatus::Active, $shared->status);
        $this->assertSame('표에서 온 보상', $shared->rewards);
        // 중복 없이 1건만
        $this->assertCount(1, collect($dtos)->where('code', 'SHARED123X'));
    }

    public function test_collect_returns_empty_when_no_url(): void
    {
        $this->assertSame([], $this->driver->collect('genshin', []));
    }

    public function test_collect_returns_empty_when_fetch_fails(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame([], $this->driver->collect('genshin', ['url' => 'https://example.test/x']));
    }
}
