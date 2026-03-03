<?php

namespace App\Services\OtakuShop\Crawler\Contracts;

use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;

interface ShopCrawlerInterface
{
    /**
     * 크롤링 대상 샵 코드 (config otaku-crawler.shops ok_shop_code).
     */
    public function getShopCode(): string;

    /**
     * 해당 샵에서 상품 목록을 크롤링하여 DTO 배열로 반환.
     *
     * @return array<int, CrawledProductDto>
     */
    public function crawlProducts(): array;
}
