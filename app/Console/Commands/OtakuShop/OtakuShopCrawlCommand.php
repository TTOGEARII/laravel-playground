<?php

namespace App\Console\Commands\OtakuShop;

use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\ShopCrawlRunner;
use Illuminate\Console\Command;

/**
 * 일상 업데이트 크롤: config 의 지정 카테고리만 수집해 신규 등록·가격/재고 갱신.
 * 최초 1회 전량 적재는 otaku-shop:crawl-full 을 사용한다.
 */
class OtakuShopCrawlCommand extends Command
{
    protected $signature = 'otaku-shop:crawl
                            {--incremental : 기존 오퍼는 가격/URL만 갱신(증분)}';

    protected $description = '오타쿠샵 3사 업데이트 크롤 (신규/가격/재고 갱신)';

    public function handle(ShopCrawlRunner $runner, CrawlSyncService $syncService): int
    {
        // 샵/카테고리는 firstOrCreate(멱등)이며, 상품·오퍼 동기화 시 코드→ID 매핑에 반드시 필요하므로
        // 증분 모드에서도 항상 먼저 보장한다. (빈 DB + 증분 스케줄에서 아무것도 적재되지 않던 문제 방지)
        $this->info('1. 샵/카테고리 동기화...');
        $syncService->syncShops();
        $syncService->syncCategories();

        if ($this->option('incremental')) {
            $this->info('증분 모드: 기존 오퍼는 가격/URL만 갱신합니다.');
        }

        $this->info('2. 3사 크롤링 수집...');
        $crawled = $runner->run(full: false, onLine: fn (string $line) => $this->line('  '.$line));
        $this->info('수집 상품 수: '.count($crawled));

        if ($crawled === []) {
            $this->warn('크롤된 상품이 없습니다. Selenium 드라이버(Chrome) 실행 여부를 확인하세요.');

            return self::FAILURE;
        }

        $this->info('3. 상품·오퍼 동기화...');
        $stats = $syncService->syncProductsAndOffers($crawled, (bool) $this->option('incremental'));
        $this->table(
            ['항목', '건수'],
            [
                ['신규 상품', $stats['products_created']],
                ['매칭 상품', $stats['products_matched']],
                ['신규 오퍼', $stats['offers_created']],
                ['업데이트 오퍼', $stats['offers_updated']],
            ]
        );

        return self::SUCCESS;
    }
}
