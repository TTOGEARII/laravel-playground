<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * 피규어프레소 (figurepresso.com) 크롤러. cafe24 기반(SEO URL 스킨).
 *
 * 표준 cafe24와 달리 상세 링크가 product/detail.html 이 아니라 SEO 친화 URL(/product/<슬러그>/<번호>/)이라,
 * 상품번호를 카드 li 의 id(anchorBoxId_<번호>)에서 뽑고 상세 URL은 canonical(detail.html?product_no=)로 만든다.
 * 그 외(제목/가격/이미지/품절/페이지네이션)는 cafe24 공통이라 Cafe24ShopCrawler 를 상속한다.
 */
class FigurePressoCrawler extends Cafe24ShopCrawler
{
    public function getShopCode(): string
    {
        return 'figurepresso';
    }

    protected function baseUrl(): string
    {
        return 'https://figurepresso.com';
    }

    /**
     * 전량 크롤용: 피규어프레소는 list.html 외에 listmaker.html(제조사별)·preorder.html(예약)·
     * listgoods.html(굿즈) 등 list 변형 페이지를 쓰므로 모두 발견한다.
     */
    protected function categoryDiscoveryScript(): string
    {
        return <<<'JS'
            const set = new Set();
            document.querySelectorAll('a[href*="cate_no="]').forEach((a) => {
                const href = a.getAttribute('href') || '';
                const m = href.match(/\/product\/(list|listmaker|preorder|listgoods)\.html\?cate_no=(\d+)/);
                if (m) set.add('/product/' + m[1] + '.html?cate_no=' + m[2]);
            });
            return JSON.stringify([...set]);
            JS;
    }

    protected function listScript(): string
    {
        return <<<'JS'
            const out = [];
            const seen = new Set();
            document.querySelectorAll('ul.prdList li[id^="anchorBoxId_"], ul.grid3 li[id^="anchorBoxId_"]').forEach((li) => {
                const idm = (li.getAttribute('id') || '').match(/anchorBoxId_(\d+)/);
                if (!idm || seen.has(idm[1])) return;
                seen.add(idm[1]);
                const id = idm[1];

                const nameEl = li.querySelector('p.name a, p.name, .description .name a, .name a, .name');
                let title = (nameEl ? nameEl.textContent : '').replace(/상품명\s*:/, '').replace(/^[:\s]+/, '').replace(/\s+/g, ' ').trim();
                if (!title) {
                    const altImg = li.querySelector('img[alt]');
                    if (altImg) title = (altImg.getAttribute('alt') || '').replace(/\s+/g, ' ').trim();
                }

                const text = li.textContent.replace(/\s+/g, ' ');
                let pm = text.match(/판매가\s*:?\s*(\d{1,3}(?:,\d{3})*)\s*원/);
                if (!pm) pm = text.match(/(\d{1,3}(?:,\d{3})+)\s*원/);
                const price = pm ? pm[1].replace(/,/g, '') : '';

                let img = '';
                li.querySelectorAll('img').forEach((im) => {
                    if (img) return;
                    const s = im.getAttribute('src') || im.getAttribute('ec-data-src') || im.getAttribute('data-src') || '';
                    if (s.indexOf('/web/product/') !== -1) img = s;
                });

                const soldout = !!li.querySelector('img[src*="soldout" i], img[alt*="품절"]');

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

                // 상세 URL 은 슬러그 대신 canonical 형식으로 통일.
                out.push({ id, title, price, url: '/product/detail.html?product_no=' + id, img, soldout, maker, release });
            });
            return JSON.stringify(out);
            JS;
    }
}
