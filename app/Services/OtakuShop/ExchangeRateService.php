<?php

namespace App\Services\OtakuShop;

use App\Models\OtakuShop\OtakuExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 해외 샵 가격 원화 환산용 환율 수집/조회.
 *
 * 무료 환율 API(키 불필요) 2원화 — 1차 open.er-api.com, 실패 시 jsdelivr currency-api 폴백.
 * 수집 실패 시 기존 저장 환율을 그대로 쓴다(사이트가 환율 API 장애에 좌우되지 않게).
 */
class ExchangeRateService
{
    /** 수집 대상 통화(해외 샵 통화). */
    private const CURRENCIES = ['JPY', 'USD'];

    /**
     * 환율을 수집해 저장한다.
     *
     * @return array<string, float> 갱신된 통화 => 1통화당 원화. 실패 시 빈 배열(기존 값 유지).
     */
    public function fetchAndStore(): array
    {
        $rates = $this->fetchKrwRates();

        $updated = [];
        foreach ($rates as $currency => $krwPerUnit) {
            OtakuExchangeRate::updateOrCreate(
                ['ok_rate_currency' => $currency],
                ['ok_rate_krw' => $krwPerUnit],
            );
            $updated[$currency] = $krwPerUnit;
        }

        return $updated;
    }

    /** 통화 금액을 원화로 환산한다. 환율 미보유 통화면 null(표시층에서 환산 생략). */
    public function toKrw(float $amount, string $currency): ?float
    {
        if (strtoupper($currency) === 'KRW') {
            return $amount;
        }
        $rate = $this->rateFor($currency);

        return $rate !== null ? round($amount * $rate, 2) : null;
    }

    /** 저장된 환율(1통화당 원화). 없으면 null. */
    public function rateFor(string $currency): ?float
    {
        $rate = OtakuExchangeRate::where('ok_rate_currency', strtoupper($currency))->value('ok_rate_krw');

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * 소스에서 통화별 원화 환산율을 가져온다(1차 → 폴백 순).
     *
     * @return array<string, float>
     */
    private function fetchKrwRates(): array
    {
        foreach (['fromErApi', 'fromJsdelivr'] as $source) {
            try {
                $rates = $this->{$source}();
                if ($rates !== []) {
                    return $rates;
                }
            } catch (\Throwable $e) {
                Log::warning("환율 수집 실패({$source}) — 다음 소스로 폴백", ['error' => $e->getMessage()]);
            }
        }

        Log::warning('환율 수집 전 소스 실패 — 기존 저장 환율 유지');

        return [];
    }

    /** open.er-api.com — KRW 기준 시세를 받아 역수로 1통화당 원화를 만든다. */
    private function fromErApi(): array
    {
        $res = Http::timeout(10)->get('https://open.er-api.com/v6/latest/KRW');
        if (! $res->ok() || $res->json('result') !== 'success') {
            return [];
        }

        $rates = [];
        foreach (self::CURRENCIES as $currency) {
            $perKrw = (float) $res->json("rates.{$currency}", 0); // 1원당 통화
            if ($perKrw > 0) {
                $rates[$currency] = round(1 / $perKrw, 6);
            }
        }

        return $rates;
    }

    /** jsdelivr currency-api(fawazahmed0) 폴백 — 동일하게 KRW 기준 역수. */
    private function fromJsdelivr(): array
    {
        $res = Http::timeout(10)->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/krw.json');
        if (! $res->ok()) {
            return [];
        }

        $rates = [];
        foreach (self::CURRENCIES as $currency) {
            $perKrw = (float) $res->json('krw.'.strtolower($currency), 0);
            if ($perKrw > 0) {
                $rates[$currency] = round(1 / $perKrw, 6);
            }
        }

        return $rates;
    }
}
