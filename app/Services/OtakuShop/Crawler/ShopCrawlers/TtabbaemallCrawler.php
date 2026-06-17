<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * 따빼몰 (ttabbaemall.co.kr) 크롤러. cafe24 기반.
 * 리스트 대상은 config(otaku-crawler.listings.ttabbaemall) 참조.
 */
class TtabbaemallCrawler extends Cafe24ShopCrawler
{
    public function getShopCode(): string
    {
        return 'ttabbaemall';
    }

    protected function baseUrl(): string
    {
        return 'https://ttabbaemall.co.kr';
    }
}
