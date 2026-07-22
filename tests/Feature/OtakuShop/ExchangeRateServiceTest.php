<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuExchangeRate;
use App\Services\OtakuShop\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ExchangeRateService
    {
        return $this->app->make(ExchangeRateService::class);
    }

    public function test_fetch_stores_rates_from_primary_source(): void
    {
        // KRW 기준 시세(1원당 통화) → 역수로 1통화당 원화 저장.
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['JPY' => 0.11, 'USD' => 0.00072]]),
        ]);

        $updated = $this->service()->fetchAndStore();

        $this->assertEqualsWithDelta(9.090909, $updated['JPY'], 0.001);
        $this->assertEqualsWithDelta(1388.888889, $updated['USD'], 0.01);
        $this->assertSame(2, OtakuExchangeRate::count());
    }

    public function test_falls_back_to_jsdelivr_when_primary_fails(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(null, 500),
            'cdn.jsdelivr.net/*' => Http::response(['krw' => ['jpy' => 0.1, 'usd' => 0.0007]]),
        ]);

        $updated = $this->service()->fetchAndStore();

        $this->assertEqualsWithDelta(10.0, $updated['JPY'], 0.001);
        $this->assertSame(2, OtakuExchangeRate::count());
    }

    public function test_keeps_existing_rates_when_all_sources_fail(): void
    {
        OtakuExchangeRate::create(['ok_rate_currency' => 'JPY', 'ok_rate_krw' => 9.5]);
        Http::fake([
            'open.er-api.com/*' => Http::response(null, 500),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);

        $updated = $this->service()->fetchAndStore();

        $this->assertSame([], $updated, '전 소스 실패 시 갱신 없음');
        $this->assertEqualsWithDelta(9.5, $this->service()->rateFor('JPY'), 0.001, '기존 환율 유지');
    }

    public function test_to_krw_converts_and_handles_edge_cases(): void
    {
        OtakuExchangeRate::create(['ok_rate_currency' => 'JPY', 'ok_rate_krw' => 9.2]);
        $service = $this->service();

        $this->assertEqualsWithDelta(117760.0, $service->toKrw(12800, 'JPY'), 0.01); // ¥12,800 → 원화
        $this->assertSame(50000.0, $service->toKrw(50000, 'KRW'), 'KRW 는 그대로');
        $this->assertNull($service->toKrw(100, 'EUR'), '환율 미보유 통화는 null(환산 생략)');
    }

    public function test_fetch_rates_command_runs(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['JPY' => 0.11, 'USD' => 0.00072]]),
        ]);

        $this->artisan('otaku-shop:fetch-rates')->assertSuccessful();

        $this->assertSame(2, OtakuExchangeRate::count());
    }
}
