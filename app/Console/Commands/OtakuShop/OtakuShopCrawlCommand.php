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

        // 샵 하나가 끝날 때마다 바로 DB에 저장한다. 뒤 샵이 실패해도 앞 샵 데이터는 안전하다.
        $this->info('2. 3사 크롤링 + 샵별 즉시 저장...');
        $incremental = (bool) $this->option('incremental');
        $stats = ['products_created' => 0, 'products_matched' => 0, 'offers_created' => 0, 'offers_updated' => 0];
        $total = $runner->run(
            full: false,
            onLine: fn (string $line) => $this->line('  '.$line),
            onShop: function (string $name, array $items) use ($syncService, $incremental, &$stats): void {
                $s = $syncService->syncProductsAndOffers($items, $incremental);
                foreach ($stats as $key => $value) {
                    $stats[$key] += $s[$key];
                }
                $this->line("    ↳ [{$name}] 저장: 신규상품 {$s['products_created']} · 매칭 {$s['products_matched']} · 신규오퍼 {$s['offers_created']} · 갱신오퍼 {$s['offers_updated']}");
            }
        );
        $this->info('수집 상품 수: '.$total);

        if ($total === 0) {
            $this->warn('크롤된 상품이 없습니다. Selenium 드라이버(Chrome) 실행 여부를 확인하세요.');

            return self::FAILURE;
        }

        $this->info('3. 누적 저장 결과:');
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
