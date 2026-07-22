<?php

namespace App\Console\Commands\OtakuShop;

use App\Services\OtakuShop\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * 해외 샵 원화 환산용 환율 수집(일 1회 스케줄). 실패해도 기존 환율이 유지되므로 사이트에 영향 없다.
 */
class OtakuShopFetchRatesCommand extends Command
{
    protected $signature = 'otaku-shop:fetch-rates';

    protected $description = '해외 샵 가격 원화 환산용 환율을 수집해 저장';

    public function handle(ExchangeRateService $service): int
    {
        $updated = $service->fetchAndStore();

        if ($updated === []) {
            $this->warn('환율 수집 실패 — 기존 저장 환율을 유지합니다.');

            return self::SUCCESS; // 실패해도 배치 실패로 처리하지 않는다(기존 값으로 동작)
        }

        foreach ($updated as $currency => $krw) {
            $this->line("  {$currency} = ₩".number_format($krw, 2));
        }
        $this->info('환율 '.count($updated).'개 통화 갱신 완료');

        return self::SUCCESS;
    }
}
