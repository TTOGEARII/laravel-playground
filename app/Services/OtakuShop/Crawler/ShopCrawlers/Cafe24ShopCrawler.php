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

                out.push({ id: idMatch[1], title, price, url: href, img });
            });
            return JSON.stringify(out);
            JS;
    }
}
