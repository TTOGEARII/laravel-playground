<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * 애니메이트코리아 온라인샵 (animate-onlineshop.co.kr) 크롤러. 고도몰(godo) 기반.
 *
 * 고도몰 상품 리스트(goods/goods_list.php?cateCd=)는 공통적으로
 *   - 상품 카드: .item_cont
 *   - 상세 링크: a[href*="goods_view.php?goodsNo="]
 *   - 제목      : .item_name (앞에 【카테고리】 접두사가 붙어 카테고리 보정에 활용)
 *   - 가격      : .item_price ("N,NNN 원")
 *   - 이미지    : 상세 링크 안 img / .item_photo_box img
 * 구조를 가진다. 카테고리는 config(otaku-crawler.listings.animate) + 제목 키워드로 결정.
 */
class AnimateCrawler extends AbstractShopCrawler
{
    public function getShopCode(): string
    {
        return 'animate';
    }

    protected function baseUrl(): string
    {
        return 'https://www.animate-onlineshop.co.kr';
    }

    protected function listScript(): string
    {
        return <<<'JS'
            const out = [];
            const items = document.querySelectorAll('.goods_list .item_cont, .item_gallery_type .item_cont, li .item_cont');
            items.forEach((item) => {
                const a = item.querySelector('a[href*="goods_view.php?goodsNo="]');
                if (!a) return;
                const href = a.getAttribute('href') || '';
                const idMatch = href.match(/goodsNo=(\d+)/);
                if (!idMatch) return;

                const nameEl = item.querySelector('.item_name');
                const title = nameEl ? nameEl.textContent.replace(/\s+/g, ' ').trim() : '';

                const priceEl = item.querySelector('.item_price, .item_money_box');
                const priceText = (priceEl ? priceEl.textContent : item.textContent).replace(/\s+/g, ' ');
                const pm = priceText.match(/(\d{1,3}(?:,\d{3})*)\s*원/);
                const price = pm ? pm[1].replace(/,/g, '') : '';

                const img = item.querySelector('a[href*="goods_view"] img, .item_photo_box img, img.middle');
                const imgSrc = img ? (img.getAttribute('src') || '') : '';

                out.push({ id: idMatch[1], title, price, url: 'goods/goods_view.php?goodsNo=' + idMatch[1], img: imgSrc });
            });
            return JSON.stringify(out);
            JS;
    }
}
