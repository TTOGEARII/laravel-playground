<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

/**
 * 굿스마일코리아 네이버 브랜드스토어 (brand.naver.com/goodsmilekr) 크롤러.
 *
 * 제조사(굿스마일컴퍼니) 공식 스토어라 제목에 넨도/피그마 번호가 들어 있어, 재판매몰 상품과
 * 고유값(maker code)으로 바로 매칭된다(공식가 vs 재판매가 비교).
 *
 * 네이버 브랜드스토어 특성:
 *   - 카테고리 리스트: /goodsmilekr/category/<해시> (40개/페이지)
 *   - 상품 상세       : /goodsmilekr/products/<숫자ID>
 *   - CSS 클래스가 난독화(해시)라 구조 기반으로 파싱한다: a[href*="/products/"] → 카드(li)
 *   - 링크/이미지는 a.href / img.src(절대 URL 프로퍼티)로 받아 상대경로 이중접두사 문제를 피한다.
 *   - 페이지네이션이 URL(?cp/?page)이 아니라 JS 클릭식이라, URL로는 1페이지만 나온다.
 *     그래서 카테고리별 "최신 1페이지(40개)"만 수집한다(신상품·예약 위주라 비교 가치 높음).
 *     이 때문에 전량 크롤의 '사라짐=품절' 대상에서는 제외한다(config crawl.no_disappear_soldout_shops).
 */
class GoodsmileCrawler extends AbstractShopCrawler
{
    public function getShopCode(): string
    {
        return 'goodsmilekr';
    }

    protected function baseUrl(): string
    {
        return 'https://brand.naver.com/goodsmilekr';
    }

    /**
     * URL 페이지네이션이 안 먹어(SPA 클릭식) 전량 모드에서도 config의 카테고리별 1페이지만 수집한다.
     *
     * @return array<int, array{path: string, category?: string|null, pages?: int}>
     */
    protected function listingTargets(): array
    {
        return config('otaku-crawler.listings.'.$this->getShopCode(), []);
    }

    protected function listScript(): string
    {
        return <<<'JS'
            const out = [];
            const seen = new Set();
            document.querySelectorAll('a[href*="/products/"]').forEach((a) => {
                const m = (a.getAttribute('href') || '').match(/\/products\/(\d+)/);
                if (!m || seen.has(m[1])) return;
                const li = a.closest('li');
                if (!li) return;
                seen.add(m[1]);

                let text = (li.innerText || '').replace(/\s+/g, ' ').trim();

                // 제목: "찜하기" 앞부분에서 선행 배지(NEW/BEST/예약/품절)와 후행 가격을 제거.
                let title = text;
                const ji = title.indexOf('찜하기');
                if (ji > -1) title = title.slice(0, ji);
                title = title.replace(/^(NEW|BEST|HOT|품절|예약)\s+/i, '').trim();
                title = title.replace(/\s*\d{1,3}(?:,\d{3})*\s*원.*$/, '').trim();

                // 가격: "배송비" 앞쪽의 마지막 가격을 상품가로(정가/할인가 동시 노출 시 할인가).
                let pricePart = text;
                const si = pricePart.indexOf('배송비');
                if (si > -1) pricePart = pricePart.slice(0, si);
                const prices = [...pricePart.matchAll(/(\d{1,3}(?:,\d{3})*)\s*원/g)].map((x) => x[1].replace(/,/g, ''));
                const price = prices.length ? prices[prices.length - 1] : '';

                const soldout = /품절|일시품절|SOLD\s*OUT/i.test(text);

                const im = li.querySelector('img');
                const img = im ? (im.src || im.getAttribute('data-src') || '') : '';

                out.push({ id: m[1], title, price, url: a.href, img, soldout });
            });
            return JSON.stringify(out);
            JS;
    }
}
