<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * 코믹스아트 (comics-art.co.kr) 크롤러. cafe24 기반(표준 스킨).
 * 리스트/상세 마크업이 cafe24 표준이라 Cafe24ShopCrawler 를 그대로 재사용한다.
 * 리스트 대상은 config(otaku-crawler.listings.comicsart) 참조, 전량 모드는 cafe24 카테고리 자동 발견.
 */
class ComicsArtCrawler extends Cafe24ShopCrawler
{
    public function getShopCode(): string
    {
        return 'comicsart';
    }

    protected function baseUrl(): string
    {
        return 'https://comics-art.co.kr';
    }
}
