<?php

namespace App\Services\OtakuShop\Crawler;

use App\Services\OtakuShop\Crawler\ShopCrawlers\AbstractShopCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\AnimateCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\DokidokigoodsCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\TtabbaemallCrawler;

/**
 * 3사 크롤러를 Selenium 드라이버 위에서 순차 실행해 DTO 를 모은다.
 * 일반 크롤(otaku-shop:crawl)과 전량 크롤(otaku-shop:crawl-full)이 공유한다.
 */
class ShopCrawlRunner
{
    /**
     * @param  bool  $full  true 이면 전량 모드(카테고리 자동 발견·끝 페이지까지·긴 딜레이).
     * @param  \Closure(string):void|null  $onLine  진행 로그 콜백(명령어 출력용).
     * @return array<int, \App\Services\OtakuShop\Crawler\DTO\CrawledProductDto>
     */
    public function run(bool $full = false, ?\Closure $onLine = null): array
    {
        $driver = CrawlerDriver::fromConfig();

        try {
            $driver->start();
        } catch (\Throwable $e) {
            // Selenium(Chrome) 미가동/연결 실패 시 스택트레이스 대신 안내 후 빈 결과 반환.
            report($e);
            $onLine && $onLine('Selenium WebDriver 연결 실패: '.$e->getMessage());

            return [];
        }

        $crawlers = $this->makeCrawlers($driver->getDriver(), $full);

        $all = [];
        $shopDelayMs = (int) config(
            $full ? 'otaku-crawler.crawl.full.delay_ms_between_shops' : 'otaku-crawler.crawl.delay_ms_between_shops',
            $full ? 8000 : 2000
        );
        $first = true;

        foreach ($crawlers as $name => $crawler) {
            if (! $first && $shopDelayMs > 0) {
                usleep($shopDelayMs * 1000);
            }
            $first = false;

            $onLine && $onLine("크롤링: {$name}...");
            try {
                $items = $crawler->crawlProducts();
                foreach ($items as $dto) {
                    $all[] = $dto;
                }
                $onLine && $onLine('  → '.count($items).'건');
            } catch (\Throwable $e) {
                report($e);
                $onLine && $onLine('  오류: '.$e->getMessage());
            }
        }

        $driver->quit();

        return $all;
    }

    /**
     * @return array<string, AbstractShopCrawler>
     */
    private function makeCrawlers($webDriver, bool $full): array
    {
        $crawlers = [
            '도키도키굿즈' => new DokidokigoodsCrawler($webDriver),
            '애니메이트' => new AnimateCrawler($webDriver),
            '따빼몰' => new TtabbaemallCrawler($webDriver),
        ];

        if ($full) {
            foreach ($crawlers as $crawler) {
                $crawler->enableFullMode();
            }
        }

        return $crawlers;
    }
}
