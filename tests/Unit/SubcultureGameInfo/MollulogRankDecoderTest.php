<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\Raids\AlternativeParties\MollulogRankDecoder;
use RuntimeException;
use Tests\TestCase;

/**
 * MollulogRankDecoder: ranks.baql.net 실응답 바이너리(protobuf)를 스키마대로 파싱한다.
 * 픽스처는 실서버 응답 그대로 — mollulog_ranks.bin(10059 제외 필터), nofilter.bin(무필터 1위).
 */
class MollulogRankDecoderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/SubcultureGameInfo/{$name}"));
    }

    public function test_실응답_바이너리를_전체_구조로_디코딩한다(): void
    {
        $decoded = (new MollulogRankDecoder)->decode($this->fixture('mollulog_ranks.bin'));

        $this->assertSame(9859, $decoded['total_count']);
        $this->assertCount(3, $decoded['ranks']);

        $first = $decoded['ranks'][0];
        $this->assertSame(52763770, $first['score']);
        $this->assertSame(15682, $first['rank']);
        $this->assertSame(15682, $first['final_rank']);
        $this->assertCount(3, $first['parties']); // 3웨이브 클리어

        // 1웨이브 첫 슬롯: uid/레벨/성급/전용무기 필드 매핑
        $student = $first['parties'][0][0];
        $this->assertSame('10111', $student['uid']);
        $this->assertSame(90, $student['level']);
        $this->assertSame(5, $student['tier']);
        $this->assertSame(3, $student['weapon_tier']);
        $this->assertFalse($student['is_assist']);

        // 3웨이브 둘째 슬롯은 조력자(is_assist) 플래그가 켜져 있다
        $this->assertSame('10111', $first['parties'][2][1]['uid']);
        $this->assertTrue($first['parties'][2][1]['is_assist']);
    }

    public function test_무필터_응답도_동일_스키마로_디코딩한다(): void
    {
        $decoded = (new MollulogRankDecoder)->decode($this->fixture('nofilter.bin'));

        $this->assertSame(48593, $decoded['total_count']);
        $this->assertCount(1, $decoded['ranks']);
        $this->assertSame(1, $decoded['ranks'][0]['rank']);
        $this->assertSame(53204505, $decoded['ranks'][0]['score']);
        $this->assertCount(2, $decoded['ranks'][0]['parties']);
        $this->assertSame('10059', $decoded['ranks'][0]['parties'][0][0]['uid']);
        $this->assertSame(4, $decoded['ranks'][0]['parties'][0][0]['weapon_tier']);
    }

    public function test_빈_바이너리는_빈_결과를_반환한다(): void
    {
        $decoded = (new MollulogRankDecoder)->decode('');

        $this->assertSame(['total_count' => 0, 'ranks' => []], $decoded);
    }

    public function test_잘린_바이너리는_예외를_던진다(): void
    {
        $this->expectException(RuntimeException::class);

        // 유효 응답을 중간에서 자르면 길이 선언과 실제 바이트가 어긋난다
        (new MollulogRankDecoder)->decode(substr($this->fixture('nofilter.bin'), 0, 50));
    }

    public function test_html_등_비_protobuf_응답은_예외를_던진다(): void
    {
        $this->expectException(RuntimeException::class);

        (new MollulogRankDecoder)->decode('<html><body>Service Unavailable</body></html>');
    }
}
