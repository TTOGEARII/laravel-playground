<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

use App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use Facebook\WebDriver\Remote\RemoteWebDriver;

abstract class AbstractShopCrawler implements ShopCrawlerInterface
{
    public function __construct(
        protected RemoteWebDriver $driver
    ) {}

    /**
     * 상품 목록 페이지 URL (서브클래스에서 오버라이드).
     */
    abstract protected function getListingUrl(): string;

    /**
     * 한 페이지에서 상품 DTO 추출 (서브클래스 구현).
     *
     * @return array<int, CrawledProductDto>
     */
    abstract protected function parseProductItems(): array;

    /**
     * 다음 페이지 존재 여부.
     */
    protected function hasNextPage(): bool
    {
        return false;
    }

    /**
     * 다음 페이지로 이동.
     */
    protected function goToNextPage(): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function crawlProducts(): array
    {
        $all = [];
        $this->driver->get($this->getListingUrl());
        $page = 1;
        $maxPages = 50;

        do {
            $items = $this->parseProductItems();
            foreach ($items as $dto) {
                $all[] = $dto;
            }
            if (! $this->hasNextPage() || $page >= $maxPages) {
                break;
            }
            $this->goToNextPage();
            $page++;
        } while (true);

        return $all;
    }

    /**
     * 요청 사이에 짧은 딜레이를 주어 트래픽 급증을 방지.
     */
    protected function delayBetweenRequests(): void
    {
        $ms = (int) config('otaku-crawler.crawl.delay_ms_between_requests', 1500);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
