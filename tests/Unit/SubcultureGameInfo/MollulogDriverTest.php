<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\MollulogDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MollulogDriver: mollulog /coupons.data(turbo-stream)를 풀어 블루아카이브 쿠폰을
 * code + 만료일(넥슨 공식 링크)로 수집. 만료일 기준으로 active/expired 판정.
 */
class MollulogDriverTest extends TestCase
{
    /** 두 쿠폰(활성/만료)을 담은 최소 turbo-stream(인덱스 참조 형식). */
    private function fakeData(): void
    {
        // idx: 0 "coupons", 1 refs[2,9], 2 coupon1 obj, 3~8 키/값, 9 coupon2 obj, 10~12 값
        $stream = json_encode([
            'coupons',                          // 0
            [2, 9],                             // 1  coupons refs
            ['_3' => 5, '_4' => 6, '_7' => 8],  // 2  coupon1: key@3→val@5 ...
            'code',                             // 3
            'expiresAt',                        // 4
            'ACTIVECODE1',                      // 5
            '2099-01-01T00:00:00.000Z',         // 6
            'name',                             // 7
            '활성 쿠폰',                        // 8
            ['_3' => 10, '_4' => 11, '_7' => 12], // 9 coupon2
            'OLDCODE2026',                      // 10
            '2020-01-01T00:00:00.000Z',         // 11
            '만료 쿠폰',                        // 12
        ], JSON_UNESCAPED_UNICODE);

        Http::fake(['mollulog.net/coupons.data*' => Http::response($stream, 200)]);
    }

    public function test_parses_codes_with_expiry_and_status(): void
    {
        $this->fakeData();

        $dtos = (new MollulogDriver)->collect('bluearchive', []);
        $byCode = collect($dtos)->keyBy('code');

        $this->assertCount(2, $dtos);

        $active = $byCode['ACTIVECODE1'];
        $this->assertSame(CodeStatus::Active, $active->status);
        $this->assertSame('2099-01-01', $active->expiresAt?->format('Y-m-d'));
        $this->assertSame(SourceType::Aggregator, $active->sourceType);
        $this->assertSame('mollulog', $active->source);
        $this->assertSame('활성 쿠폰', $active->rewards);

        $expired = $byCode['OLDCODE2026'];
        $this->assertSame(CodeStatus::Expired, $expired->status);
        $this->assertSame('2020-01-01', $expired->expiresAt?->format('Y-m-d'));
    }

    public function test_only_bluearchive(): void
    {
        $this->fakeData();
        $this->assertSame([], (new MollulogDriver)->collect('genshin', []));
    }

    public function test_bad_payload_returns_empty(): void
    {
        Http::fake(['mollulog.net/coupons.data*' => Http::response('not-json', 200)]);
        $this->assertSame([], (new MollulogDriver)->collect('bluearchive', []));
    }
}
