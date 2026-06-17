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

                // 상품 카드에는 ★특전★ 배지(goods_icon/tokuten...) 같은 아이콘 img 가 실제 상품
                // 사진보다 먼저 올 수 있어, 아이콘/버튼류를 건너뛰고 실제 상품 이미지를 고른다.
                let imgSrc = '';
                const cand = item.querySelectorAll('.item_photo_box img, a[href*="goods_view"] img, img.middle, img');
                for (const im of cand) {
                    const s = im.getAttribute('src') || '';
                    if (!s) continue;
                    if (/\/icon\/|goods_icon|tokuten|\/_btn\/|blank|btn_/i.test(s)) continue;
                    imgSrc = s;
                    break;
                }

                out.push({ id: idMatch[1], title, price, url: 'goods/goods_view.php?goodsNo=' + idMatch[1], img: imgSrc });
            });
            return JSON.stringify(out);
            JS;
    }

    /**
     * 고도몰 메뉴에서 모든 상품 카테고리(goods_list.php?cateCd=) 경로를 수집(전량 크롤용).
     */
    protected function categoryDiscoveryScript(): string
    {
        return <<<'JS'
            const set = new Set();
            document.querySelectorAll('a[href*="goods_list.php?cateCd="]').forEach((a) => {
                const href = a.getAttribute('href') || '';
                const m = href.match(/cateCd=([0-9]+)/);
                if (m) set.add('/goods/goods_list.php?cateCd=' + m[1]);
            });
            return JSON.stringify([...set]);
            JS;
    }

    /**
     * 애니메이트 상품 이미지는 godohosting/cdn 모두 https 를 지원하므로 https 로 강제해
     * https 배포 환경에서의 mixed-content 차단을 방지한다.
     */
    protected function resolveImage(string $src): ?string
    {
        $resolved = parent::resolveImage($src);

        return $resolved !== null ? preg_replace('#^http://#', 'https://', $resolved) : null;
    }
}
