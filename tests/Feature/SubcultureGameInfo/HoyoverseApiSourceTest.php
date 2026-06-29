<?php

namespace Tests\Feature\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Services\SubcultureGameInfo\Sources\HoyoverseApiSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 호요버스 ennead JSON API 소스 테스트.
 * active → CodeStatus::Active, inactive → CodeStatus::Expired 로 DTO 생성.
 */
class HoyoverseApiSourceTest extends TestCase
{
    public function test_fetch_maps_active_and_inactive_to_status(): void
    {
        Http::fake([
            'api.ennead.cc/*' => Http::response([
                'active' => [
                    ['code' => 'ABC123', 'rewards' => ['Primogem x60']],
                ],
                'inactive' => [
                    ['code' => 'OLD1', 'rewards' => []],
                ],
            ]),
        ]);

        $dtos = (new HoyoverseApiSource)->fetch();

        // 엔드포인트 3종(genshin/starrail/zenless) × (active 1 + inactive 1) = 6건
        $this->assertCount(6, $dtos);

        $active = collect($dtos)->firstWhere('code', 'ABC123');
        $this->assertNotNull($active);
        $this->assertSame(CodeStatus::Active, $active->status);
        $this->assertSame('Primogem x60', $active->rewards);

        $expired = collect($dtos)->firstWhere('code', 'OLD1');
        $this->assertNotNull($expired);
        $this->assertSame(CodeStatus::Expired, $expired->status);

        // 게임 3종 모두에 대해 호출되어 각 게임 slug 가 수집됨
        $slugs = collect($dtos)->pluck('gameSlug')->unique()->sort()->values()->all();
        $this->assertSame(['genshin', 'starrail', 'zenless'], $slugs);
    }

    public function test_fetch_returns_empty_when_endpoint_fails(): void
    {
        // 재시도 대기로 테스트가 느려지지 않도록 retry 0 으로 둔다.
        config(['subculture-game-info.http.retry' => 0]);

        Http::fake([
            'api.ennead.cc/*' => Http::response('', 500),
        ]);

        $dtos = (new HoyoverseApiSource)->fetch();

        $this->assertSame([], $dtos);
    }
}
