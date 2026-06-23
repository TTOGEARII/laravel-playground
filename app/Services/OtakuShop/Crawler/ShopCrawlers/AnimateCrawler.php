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

                // 품절 판정(godo): 품절 시 상품 컨테이너에 item_soldout 클래스가 붙고
                // 썸네일에 .item_soldout_bg 오버레이가 렌더된다(비품절엔 미존재 → 오탐 없음).
                // 장바구니 버튼도 btn_shop_soldout/btn_add_soldout 로 바뀐다.
                const soldout = item.classList.contains('item_soldout')
                    || !!item.closest('.item_soldout')
                    || !!item.querySelector('.item_soldout_bg, .btn_shop_soldout, .btn_add_soldout, img[alt*="품절"]');

                out.push({ id: idMatch[1], title, price, url: 'goods/goods_view.php?goodsNo=' + idMatch[1], img: imgSrc, soldout, makercode: '' });
            });
            return JSON.stringify(out);
            JS;
    }

    /**
     * 상세 페이지에서 바코드(자체상품코드, JAN/EAN)를 뽑는다.
     * 애니메이트는 리스트에 고유값이 없고, 상세의 '자체상품코드'(예: 6978258564275)가
     * 동일상품 매칭(JAN)에 쓸 수 있는 유일한 신뢰값이라 상세 보강 시 활용한다.
     * (config crawl.fetch_detail_shops 에 'animate' 가 있을 때만 호출됨.)
     */
    protected function detailScript(): string
    {
        return <<<'JS'
            var out = { barcode: '', maker: '', soldout: false };
            document.querySelectorAll('table tr, dl').forEach(function (row) {
                var th = row.querySelector('th, dt');
                var td = row.querySelector('td, dd');
                if (!th || !td) return;
                var k = (th.textContent || '').replace(/\s+/g, '');
                var v = (td.textContent || '').replace(/\s+/g, ' ').trim();
                if (/자체상품코드|상품코드|바코드/.test(k)) {
                    var digits = v.replace(/\D/g, '');
                    if (!out.barcode && digits.length >= 8 && digits.length <= 13) out.barcode = digits;
                }
            });
            // 상세 품절 신호(리스트에서 못 잡은 경우 보강).
            out.soldout = !!document.querySelector('.btn_soldout, .item_soldout_bg, .btn_shop_soldout, img[alt*="품절"]');
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
