<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * 도키도키굿즈 (dokidokigoods.co.kr) 크롤러. cafe24 기반.
 * 리스트 대상은 config(otaku-crawler.listings.dokidokigoods) 참조.
 */
class DokidokigoodsCrawler extends Cafe24ShopCrawler
{
    public function getShopCode(): string
    {
        return 'dokidokigoods';
    }

    protected function baseUrl(): string
    {
        return 'https://dokidokigoods.co.kr';
    }
}
