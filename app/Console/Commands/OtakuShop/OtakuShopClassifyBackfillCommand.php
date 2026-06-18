<?php

namespace App\Console\Commands\OtakuShop;

use App\Models\OtakuShop\OtakuIp;
use App\Models\OtakuShop\OtakuProduct;
use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\ProductNormalizer;
use Illuminate\Console\Command;

/**
 * 기존 상품에 발매(예정)일·IP(작품) 분류를 소급 적용한다(제목 재파싱).
 * 크롤은 앞으로 자동 분류하지만, 이미 적재된 상품은 이 커맨드로 한 번 채운다.
 *
 *   --force : 이미 값이 있는 것도 덮어쓴다(사전 확장 후 재분류용). 기본은 비어 있는 것만 채움.
 */
class OtakuShopClassifyBackfillCommand extends Command
{
    protected $signature = 'otaku-shop:classify-backfill
                            {--force : 기존 값이 있어도 다시 분류해 덮어쓴다(사전 확장 후 전체 재분류)}';

    protected $description = '기존 상품에 발매일·IP(작품) 분류를 제목에서 소급 적용';

    public function handle(CrawlSyncService $syncService, ProductNormalizer $normalizer): int
    {
        $force = (bool) $this->option('force');

        $this->info('1. IP 사전 동기화(otaku_ip)...');
        $syncService->syncIps();
        $ipIdByCode = OtakuIp::pluck('ok_ip_id', 'ok_ip_code')->all();

        $this->info('2. 상품 재분류('.($force ? '전체 덮어쓰기' : '빈 값만 채움').')...');
        $total = OtakuProduct::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dateSet = 0;
        $ipSet = 0;
        OtakuProduct::query()->chunkById(1000, function ($products) use ($normalizer, $ipIdByCode, $force, &$dateSet, &$ipSet, $bar) {
            foreach ($products as $product) {
                $title = (string) $product->ok_product_title;

                if ($force || $product->ok_product_release_date === null) {
                    $date = $normalizer->extractReleaseDate($title);
                    if ($date !== null) {
                        $product->ok_product_release_date = $date;
                    }
                }
                if ($force || $product->ok_product_ip_id === null) {
                    $code = $normalizer->extractIpCode($title);
                    $ipId = $code !== null ? ($ipIdByCode[$code] ?? null) : null;
                    if ($ipId !== null) {
                        $product->ok_product_ip_id = $ipId;
                    }
                }

                if ($product->isDirty()) {
                    if ($product->isDirty('ok_product_release_date')) {
                        $dateSet++;
                    }
                    if ($product->isDirty('ok_product_ip_id')) {
                        $ipSet++;
                    }
                    $product->save();
                }
                $bar->advance();
            }
        }, 'ok_product_id');

        $bar->finish();
        $this->newLine(2);
        $this->info("완료: 발매일 {$dateSet}건 · IP {$ipSet}건 설정");

        return self::SUCCESS;
    }
}
