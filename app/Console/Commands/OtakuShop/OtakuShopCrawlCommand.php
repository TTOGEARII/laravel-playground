<?php

namespace App\Console\Commands\OtakuShop;

use App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface;
use App\Services\OtakuShop\Crawler\CrawlerDriver;
use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\ShopCrawlers\AnimateCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\DokidokigoodsCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\TtabbaemallCrawler;
use Illuminate\Console\Command;

/**
 * 전체 크롤: 1~4번 (샵/카테고리 sync → 3사 크롤 → 상품·오퍼 sync).
 */
class OtakuShopCrawlCommand extends Command
{
    protected $signature = 'otaku-shop:crawl
                            {--incremental : 3·4번만 실행, 기등록 데이터 제외·변경분만 반영}';

    protected $description = '오타쿠샵 3사 크롤링 후 DB 동기화 (전체 또는 증분)';

    public function handle(CrawlSyncService $syncService): int
    {
        $incremental = $this->option('incremental');

        // 샵/카테고리는 firstOrCreate(멱등)이며, 상품·오퍼 동기화 시 코드→ID 매핑에 반드시 필요하므로
        // 증분 모드에서도 항상 먼저 보장한다. (빈 DB + 증분 스케줄에서 아무것도 적재되지 않던 문제 방지)
        $this->info('1. 샵/카테고리 동기화...');
        $syncService->syncShops();
        $syncService->syncCategories();

        if ($incremental) {
            $this->info('증분 모드: 기존 오퍼는 가격/URL만 갱신합니다.');
        }

        $this->info('2. 3사 크롤링 수집...');
        $crawled = $this->runCrawlers();
        $this->info('수집 상품 수: '.count($crawled));

        if (count($crawled) === 0) {
            $this->warn('크롤된 상품이 없습니다. Selenium 드라이버(Chrome) 실행 여부를 확인하세요.');

            return self::FAILURE;
        }

        $this->info('3. 상품·오퍼 동기화...');
        $stats = $syncService->syncProductsAndOffers($crawled, $incremental);
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

    /**
     * 3개 샵 크롤러 실행 후 DTO 배열 병합.
     *
     * @return array<int, \App\Services\OtakuShop\Crawler\DTO\CrawledProductDto>
     */
    private function runCrawlers(): array
    {
        $driver = CrawlerDriver::fromConfig();

        try {
            $driver->start();
        } catch (\Throwable $e) {
            // Selenium(Chrome) 미가동/연결 실패 시 스택트레이스 대신 안내 후 빈 결과 반환.
            $this->error('Selenium WebDriver 연결 실패: '.$e->getMessage());
            report($e);

            return [];
        }

        $crawlers = $this->getCrawlers($driver->getDriver());

        $all = [];
        $delayBetweenShopsMs = (int) config('otaku-crawler.crawl.delay_ms_between_shops', 2000);
        $first = true;

        foreach ($crawlers as $name => $crawler) {
            if (! $first && $delayBetweenShopsMs > 0) {
                usleep($delayBetweenShopsMs * 1000);
            }
            $first = false;

            $this->line("  크롤링: {$name}...");
            try {
                $items = $crawler->crawlProducts();
                foreach ($items as $dto) {
                    $all[] = $dto;
                }
                $this->line('    → '.count($items).'건');
            } catch (\Throwable $e) {
                $this->error('    오류: '.$e->getMessage());
                report($e);
            }
        }

        $driver->quit();

        return $all;
    }

    /**
     * @return array<string, ShopCrawlerInterface>
     */
    private function getCrawlers($webDriver): array
    {
        return [
            '도키도키굿즈' => new DokidokigoodsCrawler($webDriver),
            '애니메이트' => new AnimateCrawler($webDriver),
            '따빼몰' => new TtabbaemallCrawler($webDriver),
        ];
    }
}
