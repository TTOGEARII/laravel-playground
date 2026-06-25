<?php

namespace App\Services\OtakuShop\Crawler;

use App\Services\OtakuShop\Crawler\ShopCrawlers\AbstractShopCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\AnimateCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\ComicsArtCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\DokidokigoodsCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\FigurePressoCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\GoodsmileCrawler;
use App\Services\OtakuShop\Crawler\ShopCrawlers\TtabbaemallCrawler;

/**
 * 3사 크롤러를 Selenium 드라이버 위에서 순차 실행해 DTO 를 모은다.
 * 일반 크롤(otaku-shop:crawl)과 전량 크롤(otaku-shop:crawl-full)이 공유한다.
 */
class ShopCrawlRunner
{
    /**
     * 샵별로 독립 세션을 만들어 크롤하고, 한 샵이 끝날 때마다 $onShop 으로 결과를 넘긴다.
     * 한 샵 실패가 다른 샵·이미 모은 데이터에 영향을 주지 않는다(샵별 격리 + 즉시 저장).
     *
     * @param  bool  $full  true 이면 전량 모드(카테고리 자동 발견·끝 페이지까지·긴 딜레이).
     * @param  \Closure(string):void|null  $onLine  진행 로그 콜백(명령어 출력용).
     * @param  \Closure(string, array<int, \App\Services\OtakuShop\Crawler\DTO\CrawledProductDto>):void|null  $onShop
     *                                                                                                                 한 샵 수집 완료 시 (샵명, DTO목록) 콜백. 보통 여기서 DB 저장을 한다.
     * @return int 전체 수집 상품(DTO) 수.
     */
    public function run(bool $full = false, ?\Closure $onLine = null, ?\Closure $onShop = null, ?array $onlyShops = null): int
    {
        // --shop 필터(소문자 정규화). null 이면 전체 샵 크롤(스케줄러는 미지정이라 전체 그대로).
        $only = $onlyShops !== null ? array_map(fn ($s) => strtolower(trim((string) $s)), $onlyShops) : null;

        $shopDelayMs = (int) config(
            $full ? 'otaku-crawler.crawl.full.delay_ms_between_shops' : 'otaku-crawler.crawl.delay_ms_between_shops',
            $full ? 8000 : 2000
        );

        $total = 0;
        $first = true;

        foreach ($this->shopDefinitions() as $def) {
            // --shop 지정 시 코드 또는 이름이 일치하는 샵만 크롤(미지정이면 전부).
            if ($only !== null
                && ! in_array(strtolower($def['code']), $only, true)
                && ! in_array(strtolower($def['name']), $only, true)) {
                continue;
            }
            $name = $def['name'];
            $crawlerClass = $def['class'];

            if (! $first && $shopDelayMs > 0) {
                usleep($shopDelayMs * 1000);
            }
            $first = false;

            // 샵마다 새 브라우저 세션(장시간 단일 세션 degradation 방지 + 샵 간 격리).
            $driver = CrawlerDriver::fromConfig();
            try {
                $driver->start();
            } catch (\Throwable $e) {
                report($e);
                $onLine && $onLine("크롤링: {$name}... Selenium 연결 실패: ".$e->getMessage());

                continue;
            }

            /** @var AbstractShopCrawler $crawler */
            $crawler = new $crawlerClass($driver);
            if ($full) {
                $crawler->enableFullMode();
            }

            $onLine && $onLine("크롤링: {$name}...");
            try {
                $items = $crawler->crawlProducts();
                $onLine && $onLine('  → '.count($items).'건');
                $total += count($items);
                if ($items !== [] && $onShop) {
                    $onShop($name, $items);
                }
            } catch (\Throwable $e) {
                report($e);
                $onLine && $onLine('  오류: '.$e->getMessage());
            } finally {
                $driver->quit();
            }
        }

        return $total;
    }

    /**
     * @return array<int, array{name: string, code: string, class: class-string<AbstractShopCrawler>}>
     */
    private function shopDefinitions(): array
    {
        return [
            ['name' => '도키도키굿즈', 'code' => 'dokidokigoods', 'class' => DokidokigoodsCrawler::class],
            ['name' => '애니메이트', 'code' => 'animate', 'class' => AnimateCrawler::class],
            ['name' => '따빼몰', 'code' => 'ttabbaemall', 'class' => TtabbaemallCrawler::class],
            ['name' => '굿스마일코리아', 'code' => 'goodsmilekr', 'class' => GoodsmileCrawler::class],
            ['name' => '코믹스아트', 'code' => 'comicsart', 'class' => ComicsArtCrawler::class],
            ['name' => '피규어프레소', 'code' => 'figurepresso', 'class' => FigurePressoCrawler::class],
        ];
    }
}
