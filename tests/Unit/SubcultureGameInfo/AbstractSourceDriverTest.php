<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AbstractSourceDriver 의 protected 헬퍼를 화이트박스(리플렉션)로 직접 검증.
 * looksLikeCode / extractCodeTokensFromText / parseExpiry / normalizeRewards /
 * extractCodesFromLinkTitles / regionFor / hostLabel
 */
class AbstractSourceDriverTest extends TestCase
{
    private AbstractSourceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        // HtmlDriver 가 AbstractSourceDriver 구상 구현이므로 헬퍼 호출용으로 사용.
        $this->driver = new \App\Services\SubcultureGameInfo\Sources\Drivers\HtmlDriver;
    }

    /** protected 메서드 호출 헬퍼. */
    private function invokeHelper(string $method, ...$args): mixed
    {
        $m = new ReflectionMethod(AbstractSourceDriver::class, $method);

        return $m->invoke($this->driver, ...$args);
    }

    // ---------------------------------------------------------------- looksLikeCode
    public static function looksLikeCodeProvider(): array
    {
        return [
            // [token, expected]
            '대문자+숫자 정상 코드' => ['GENSHINGIFT', true],
            '대문자+숫자 혼합' => ['ZONEFEVER2024', true],
            '4자 미만 너무 짧음' => ['ABC', false],
            '6자 미만 + 숫자 없음 → 제외' => ['ABCDE', false],
            '6자 이상 순수 대문자 허용' => ['ABCDEF', true],
            '소문자 산문(대문자 비율 부족)' => ['discount', false],
            '비영숫자 포함' => ['ABC-123', false],
            '30자 초과' => [str_repeat('A', 31), false],
            'denylist HTTPS 제외' => ['HTTPS', false],
            'denylist PRIMOGEM 제외' => ['PRIMOGEM', false],
            'denylist EXPIRED 제외' => ['EXPIRED', false],
            '대문자 비율 0.6 미만(혼합)' => ['AbcdefGh', false],
        ];
    }

    /** @dataProvider looksLikeCodeProvider */
    public function test_looks_like_code(string $token, bool $expected): void
    {
        $this->assertSame($expected, $this->invokeHelper('looksLikeCode', $token), "토큰: {$token}");
    }

    // ---------------------------------------------------------------- extractCodeTokensFromText
    public function test_extract_code_tokens_dedup_and_preserve_case(): void
    {
        $text = 'Use GENSHINGIFT now! also genshingift again and ZONEFEVER2024. the http json';
        $tokens = $this->invokeHelper('extractCodeTokensFromText', $text);

        // 중복(대소문자 무시) 제거: GENSHINGIFT 1회만, 원문 케이스(첫 등장) 보존
        $this->assertContains('GENSHINGIFT', $tokens);
        $this->assertContains('ZONEFEVER2024', $tokens);
        // denylist/산문 제외
        $this->assertNotContains('http', $tokens);
        $this->assertNotContains('json', $tokens);
        // GENSHINGIFT 는 한 번만(대문자 키 dedup)
        $upper = array_map('strtoupper', $tokens);
        $this->assertSame(1, count(array_keys($upper, 'GENSHINGIFT', true)));
    }

    // ---------------------------------------------------------------- parseExpiry
    public static function parseExpiryProvider(): array
    {
        return [
            'ISO 하이픈' => ['만료 2026-06-30', '2026-06-30'],
            '점 구분' => ['~ 2026.06.30 까지', '2026-06-30'],
            '영문 월 일 콤마 연도' => ['Expires June 28, 2026', '2026-06-28'],
            '일 영문월 연도' => ['28 June 2026', '2026-06-28'],
            '미국식 슬래시' => ['06/28/2026', '2026-06-28'],
            '시간 포함' => ['2026-06-30 10:59', '2026-06-30'],
        ];
    }

    /** @dataProvider parseExpiryProvider */
    public function test_parse_expiry_parses_dates(string $text, string $expectedDate): void
    {
        /** @var Carbon|null $d */
        $d = $this->invokeHelper('parseExpiry', $text);
        $this->assertNotNull($d, "파싱 실패: {$text}");
        $this->assertSame($expectedDate, $d->format('Y-m-d'));
    }

    public function test_parse_expiry_returns_null_for_non_dates(): void
    {
        $this->assertNull($this->invokeHelper('parseExpiry', null));
        $this->assertNull($this->invokeHelper('parseExpiry', ''));
        $this->assertNull($this->invokeHelper('parseExpiry', '   '));
        $this->assertNull($this->invokeHelper('parseExpiry', '상시 / 영구'));
    }

    // ---------------------------------------------------------------- normalizeRewards
    public function test_normalize_rewards_string(): void
    {
        $this->assertSame('원석 x60', $this->invokeHelper('normalizeRewards', '원석 x60'));
        $this->assertSame('trimmed', $this->invokeHelper('normalizeRewards', '  trimmed  '));
        $this->assertNull($this->invokeHelper('normalizeRewards', '   '));
    }

    public function test_normalize_rewards_array_of_strings(): void
    {
        $this->assertSame('원석, 모라', $this->invokeHelper('normalizeRewards', ['원석', '모라']));
    }

    public function test_normalize_rewards_array_of_name_count(): void
    {
        $res = $this->invokeHelper('normalizeRewards', [
            ['name' => 'Primogem', 'count' => 60],
            ['name' => 'Mora', 'amount' => 10000],
            ['name' => 'Hero Wit'], // count 없음 → 이름만
        ]);
        $this->assertSame('Primogem x60, Mora x10000, Hero Wit', $res);
    }

    public function test_normalize_rewards_caps_at_six_items(): void
    {
        $rewards = array_map(fn ($i) => "Item{$i}", range(1, 10));
        $res = $this->invokeHelper('normalizeRewards', $rewards);
        $this->assertSame(6, substr_count($res, ',') + 1, '최대 6개로 제한');
    }

    public function test_normalize_rewards_non_array_non_string(): void
    {
        $this->assertNull($this->invokeHelper('normalizeRewards', null));
        $this->assertNull($this->invokeHelper('normalizeRewards', 123));
    }

    // ---------------------------------------------------------------- extractCodesFromLinkTitles
    public function test_extract_codes_from_link_titles_only_keyword_links(): void
    {
        $html = <<<'HTML'
        <html><body>
          <a href="/1">리딤코드 GENSHINGIFT 배포</a>
          <a href="/2">일반 잡담 RANDOMTOK9 입니다</a>
          <a href="/3">coupon ZONEFEVER2024 공유</a>
          <a href="/4">제목없음</a>
        </body></html>
        HTML;

        $codes = $this->invokeHelper('extractCodesFromLinkTitles', $html);

        // 키워드(리딤/coupon)가 든 링크 제목에서만 추출
        $this->assertContains('GENSHINGIFT', $codes);
        $this->assertContains('ZONEFEVER2024', $codes);
        // 키워드 없는 링크의 토큰은 제외
        $this->assertNotContains('RANDOMTOK9', $codes);
    }

    // ---------------------------------------------------------------- regionFor
    public function test_region_for_uses_config_default(): void
    {
        config()->set('subculture-game-info.games.genshin.region_default', 'asia');
        $this->assertSame(CodeRegion::Asia, $this->invokeHelper('regionFor', 'genshin'));
    }

    public function test_region_for_falls_back_to_global_when_unknown(): void
    {
        config()->set('subculture-game-info.games.unknowngame.region_default', 'mars');
        $this->assertSame(CodeRegion::Global, $this->invokeHelper('regionFor', 'unknowngame'));
        // 아예 설정이 없는 게임도 global
        $this->assertSame(CodeRegion::Global, $this->invokeHelper('regionFor', 'nonexistent'));
    }

    // ---------------------------------------------------------------- hostLabel
    public static function hostLabelProvider(): array
    {
        return [
            'www 제거 + 첫 레이블' => ['https://www.pockettactics.com/genshin/codes', 'pockettactics'],
            '서브도메인 첫 레이블' => ['https://mollulog.net/coupons', 'mollulog'],
            'co.kr 첫 레이블' => ['https://honeybeejoa.co.kr/bbs', 'honeybeejoa'],
        ];
    }

    /** @dataProvider hostLabelProvider */
    public function test_host_label(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->invokeHelper('hostLabel', $url));
    }
}
