<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * cafe24 기반 쇼핑몰 공통 크롤러 (도키도키굿즈/따빼몰).
 *
 * cafe24 상품 리스트 페이지(product/list.html?cate_no=)는 공통적으로
 *   - 상품 카드: ul.prdList > li[id^="anchorBoxId_"]
 *   - 상세 링크: a[href*="product/detail.html"][href*="product_no="]
 *   - 제목      : p.name a (숨김 "상품명 :" 접두사 제거)
 *   - 가격      : 카드 텍스트의 "판매가 : N원" 또는 첫 "N,NNN원"
 *   - 이미지    : src 에 /web/product/ 가 포함된 img (좋아요/장바구니 아이콘 제외)
 * 구조를 가진다. 서브클래스는 getShopCode()/baseUrl() 만 제공한다.
 */
abstract class Cafe24ShopCrawler extends AbstractShopCrawler
{
    protected function listScript(): string
    {
        return <<<'JS'
            const out = [];
            const lis = document.querySelectorAll('ul.prdList > li[id^="anchorBoxId_"], ul.prdList > li.item, ul.prdList > li.xans-record-');
            lis.forEach((li) => {
                const a = li.querySelector('a[href*="product/detail.html"][href*="product_no="]');
                if (!a) return;
                const href = a.getAttribute('href') || '';
                const idMatch = href.match(/product_no=(\d+)/);
                if (!idMatch) return;

                const nameEl = li.querySelector('p.name a, .description .name a, .name a');
                let title = nameEl ? nameEl.textContent : '';
                title = title.replace(/상품명\s*:/, '').replace(/\s+/g, ' ').trim();

                let img = '';
                li.querySelectorAll('img').forEach((im) => {
                    if (img) return;
                    const s = im.getAttribute('src') || '';
                    if (s.indexOf('/web/product/') !== -1) img = s;
                });
                if (!title) {
                    const altImg = li.querySelector('p.prdImg img, img[id^="eListPrdImage"]');
                    if (altImg) title = (altImg.getAttribute('alt') || '').replace(/\s+/g, ' ').trim();
                }

                const text = li.textContent.replace(/\s+/g, ' ');
                let price = '';
                let pm = text.match(/판매가\s*:?\s*(\d{1,3}(?:,\d{3})*)\s*원/);
                if (!pm) pm = text.match(/(\d{1,3}(?:,\d{3})+)\s*원/);
                if (pm) price = pm[1].replace(/,/g, '');

                // 품절 판정(cafe24): 품절 상품은 .icon 영역에 기본 품절 아이콘
                // (ico_product_soldout.gif, alt="품절")이 렌더된다. 실측 확인된 신호.
                const soldout = !!li.querySelector('img[src*="soldout" i], img[alt*="품절"]');

                // 상품정보 행(제조사/발매 등)을 라벨:값으로 파싱(실측: 코믹스아트 카드에 제조사·발매 노출).
                const specs = {};
                li.querySelectorAll('.spec li, ul.spec li, .xans-record- li, .description li').forEach((r) => {
                    const t = (r.textContent || '').replace(/\s+/g, ' ').trim();
                    const ci = t.indexOf(':');
                    if (ci > 0 && t.length < 70) {
                        const k = t.slice(0, ci).trim();
                        const v = t.slice(ci + 1).trim();
                        if (k && v && !specs[k]) specs[k] = v;
                    }
                });
                const maker = specs['제조사'] || specs['브랜드'] || '';
                const release = specs['발매'] || specs['발매일'] || specs['출시'] || specs['출시일'] || '';

                out.push({ id: idMatch[1], title, price, url: href, img, soldout, maker, release });
            });
            return JSON.stringify(out);
            JS;
    }

    /**
     * cafe24 메뉴에서 모든 상품 카테고리(product/list.html?cate_no=) 경로를 수집(전량 크롤용).
     */
    protected function categoryDiscoveryScript(): string
    {
        return <<<'JS'
            const set = new Set();
            document.querySelectorAll('a[href*="product/list.html?cate_no="]').forEach((a) => {
                const href = a.getAttribute('href') || '';
                const m = href.match(/cate_no=(\d+)/);
                if (m) set.add('/product/list.html?cate_no=' + m[1]);
            });
            return JSON.stringify([...set]);
            JS;
    }
}
