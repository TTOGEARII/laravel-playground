<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\EnneadApiDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\SeriaApiDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EnneadApiDriver / SeriaApiDriver 화이트박스: Http::fake JSON 목킹.
 * active/inactive · OK/NOT_OK → Active/Expired, rewards 정규화, 비호요 게임 빈 배열.
 */
class ApiDriversTest extends TestCase
{
    // ---------------------------------------------------------------- Ennead
    public function test_ennead_active_and_inactive_map_to_status(): void
    {
        Http::fake([
            'api.ennead.cc/*' => Http::response([
                'active' => [
                    ['code' => 'GENSHINGIFT', 'rewards' => [['name' => 'Primogem', 'count' => 60]]],
                    ['code' => 'ACTIVECODE2'],
                ],
                'inactive' => [
                    ['code' => 'DEADCODE111', 'rewards' => ['Mora']],
                ],
            ], 200),
        ]);

        $dtos = (new EnneadApiDriver)->collect('genshin', []);
        $byCode = collect($dtos)->keyBy('code');

        $this->assertSame(CodeStatus::Active, $byCode['GENSHINGIFT']->status);
        $this->assertSame('Primogem x60', $byCode['GENSHINGIFT']->rewards);
        $this->assertSame(CodeStatus::Active, $byCode['ACTIVECODE2']->status);
        $this->assertSame(CodeStatus::Expired, $byCode['DEADCODE111']->status);
        $this->assertSame('Mora', $byCode['DEADCODE111']->rewards);
        // sourceType/source 확인
        $this->assertSame(SourceType::Aggregator, $byCode['GENSHINGIFT']->sourceType);
        $this->assertSame('ennead', $byCode['GENSHINGIFT']->source);
    }

    public function test_ennead_skips_blank_codes(): void
    {
        Http::fake([
            'api.ennead.cc/*' => Http::response([
                'active' => [['code' => '   '], ['code' => null], ['rewards' => []], 'GENSHINGIFT'],
                'inactive' => [],
            ], 200),
        ]);

        $dtos = (new EnneadApiDriver)->collect('genshin', []);
        // 문자열 entry 'GENSHINGIFT' 는 허용, 빈/누락은 skip
        $codes = collect($dtos)->pluck('code')->all();
        $this->assertSame(['GENSHINGIFT'], $codes);
    }

    public function test_ennead_returns_empty_for_non_hoyo_game(): void
    {
        // trickcal 은 ennead.games 매핑에 없음 → HTTP 호출조차 안 함
        Http::fake(); // 호출되면 빈 응답이라 어차피 빈배열이지만, 매핑 없음 분기 확인
        $dtos = (new EnneadApiDriver)->collect('trickcal', []);
        $this->assertSame([], $dtos);
        Http::assertNothingSent();
    }

    public function test_ennead_returns_empty_on_non_array_json(): void
    {
        Http::fake(['api.ennead.cc/*' => Http::response('null', 200)]);
        $this->assertSame([], (new EnneadApiDriver)->collect('genshin', []));
    }

    // ---------------------------------------------------------------- Seria
    public function test_seria_ok_and_not_ok_map_to_status(): void
    {
        Http::fake([
            'hoyo-codes.seria.moe/*' => Http::response([
                'codes' => [
                    ['code' => 'OKCODE1234', 'status' => 'OK', 'rewards' => 'Primogem x60'],
                    ['code' => 'NOTOKCODE5', 'status' => 'NOT_OK', 'rewards' => 'old'],
                ],
            ], 200),
        ]);

        $dtos = (new SeriaApiDriver)->collect('genshin', []);
        $byCode = collect($dtos)->keyBy('code');

        $this->assertSame(CodeStatus::Active, $byCode['OKCODE1234']->status);
        $this->assertSame(CodeStatus::Expired, $byCode['NOTOKCODE5']->status);
        $this->assertSame('seria', $byCode['OKCODE1234']->source);
    }

    public function test_seria_uses_mapped_game_query_param(): void
    {
        Http::fake(['hoyo-codes.seria.moe/*' => Http::response(['codes' => []], 200)]);

        (new SeriaApiDriver)->collect('starrail', []);

        // starrail → 'hkrpg' 매핑
        Http::assertSent(fn ($request) => str_contains($request->url(), 'game=hkrpg'));
    }

    public function test_seria_returns_empty_for_non_hoyo_game(): void
    {
        Http::fake();
        $this->assertSame([], (new SeriaApiDriver)->collect('bluearchive', []));
        Http::assertNothingSent();
    }

    public function test_seria_skips_blank_codes(): void
    {
        Http::fake([
            'hoyo-codes.seria.moe/*' => Http::response([
                'codes' => [
                    ['code' => '', 'status' => 'OK'],
                    ['code' => 'GOODCODE99', 'status' => 'OK'],
                ],
            ], 200),
        ]);

        $dtos = (new SeriaApiDriver)->collect('genshin', []);
        $this->assertSame(['GOODCODE99'], collect($dtos)->pluck('code')->all());
    }
}
