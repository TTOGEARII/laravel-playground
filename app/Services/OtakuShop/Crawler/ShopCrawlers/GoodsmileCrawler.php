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

    /**
     * 네이버 브랜드스토어는 카드가 난독화 DOM이라, 카드에서 상품ID만 모아
     * 스토어 공개 JSON API(simple-products)로 정확한 가격·재고·제조사·배송비·발매일을 받는다.
     * (상세 페이지를 따로 안 열어도 리스트 1콜로 DB 컬럼을 거의 다 채운다.)
     * API 실패(레이트리밋 등) 시에는 카드 텍스트 기반 DOM 파싱으로 폴백한다(방어적).
     */
    protected function listScript(): string
    {
        return <<<'JS'
            // ── 동기 XHR (Selenium executeScript 호환) ─────────────────────────
            function getJson(url) {
                var x = new XMLHttpRequest();
                x.open('GET', url, false);
                x.setRequestHeader('accept', 'application/json');
                try { x.send(); } catch (e) { return { status: 0, body: null }; }
                var body = null;
                try { body = JSON.parse(x.responseText); } catch (e) { body = null; }
                return { status: x.status, body: body };
            }
            function sleep(ms) { var t = Date.now(); while (Date.now() - t < ms) { /* busy-wait */ } }

            // 카드에서 상품ID 수집
            var ids = [], seen = {};
            document.querySelectorAll('a[href*="/products/"]').forEach(function (a) {
                var m = (a.getAttribute('href') || '').match(/\/products\/(\d+)/);
                if (m && !seen[m[1]]) { seen[m[1]] = 1; ids.push(m[1]); }
            });

            // 채널 UID 추출(스토어별 고정값) — 페이지 내 임베디드 상태/URL에서.
            var html = document.documentElement.innerHTML;
            var cm = html.match(/"channelUid"\s*:\s*"([A-Za-z0-9_-]{12,40})"/) || html.match(/\/channels\/([A-Za-z0-9_-]{12,40})\//);
            var ch = cm ? cm[1] : null;

            var out = [];
            if (ch && ids.length) {
                for (var i = 0; i < ids.length; i += 20) {
                    var chunk = ids.slice(i, i + 20);
                    var url = '/n/v2/channels/' + ch + '/simple-products?ids[]=' + chunk.join(',')
                        + '&excludeAuthBlind=false&excludeDisplayableFilter=false&forceOrder=true';
                    var res = getJson(url);
                    if (res.status === 429) { sleep(1500); res = getJson(url); } // 레이트리밋 1회 백오프
                    var arr = Array.isArray(res.body) ? res.body : (res.body && (res.body.simpleProducts || res.body.content)) || [];
                    arr.forEach(function (p) {
                        var id = String(p.id || p.productNo || '');
                        if (!id) return;
                        var stock = (typeof p.stockQuantity === 'number') ? p.stockQuantity : null;
                        var status = p.productStatusType || '';
                        var disp = p.channelProductDisplayStatusType || '';
                        var soldout = (stock !== null && stock <= 0)
                            || /SOLDOUT|OUTOFSTOCK|SUSPENSION|CLOSE|PROHIBITION/i.test(status)
                            || (disp && disp !== 'ON');
                        var maker = (p.naverShoppingSearchInfo && p.naverShoppingSearchInfo.manufacturerName) || '';
                        var shipping = '';
                        var d = p.productDeliveryInfo;
                        if (d) {
                            if (d.deliveryFeeType === 'FREE') shipping = '0';
                            else if (typeof d.baseFee === 'number') shipping = String(d.baseFee);
                        }
                        // 발매일: 상세설명 텍스트의 "발매시기 2026/11" 또는 "발매 ... 2026년 11월".
                        var release = '';
                        var dc = (p.detailContents && p.detailContents.detailContentText) || '';
                        var rm = dc.match(/발매[^\d]{0,8}(\d{4})\s*[\/\.\-년]\s*(\d{1,2})/);
                        if (rm) release = rm[1] + '/' + rm[2];
                        out.push({
                            id: id,
                            title: (p.name || p.dispName || '').replace(/\s+/g, ' ').trim(),
                            price: String(p.salePrice || p.dispSalePrice || 0),
                            url: 'https://brand.naver.com/goodsmilekr/products/' + id,
                            img: p.representativeImageUrl || '',
                            soldout: soldout,
                            maker: maker,
                            shipping: shipping,
                            release: release
                        });
                    });
                    if (i + 20 < ids.length) sleep(300); // 청크 간 간격(레이트리밋 회피)
                }
            }

            // ── API가 아무것도 못 줬으면 카드 텍스트 DOM 파싱으로 폴백 ──────────
            if (out.length === 0) {
                var fseen = {};
                document.querySelectorAll('a[href*="/products/"]').forEach(function (a) {
                    var m = (a.getAttribute('href') || '').match(/\/products\/(\d+)/);
                    if (!m || fseen[m[1]]) return;
                    var li = a.closest('li');
                    if (!li) return;
                    fseen[m[1]] = 1;
                    var text = (li.innerText || '').replace(/\s+/g, ' ').trim();
                    var title = text;
                    var ji = title.indexOf('찜하기');
                    if (ji > -1) title = title.slice(0, ji);
                    title = title.replace(/^(NEW|BEST|HOT|품절|예약)\s+/i, '').replace(/\s*\d{1,3}(?:,\d{3})*\s*원.*$/, '').trim();
                    var pricePart = text;
                    var si = pricePart.indexOf('배송비');
                    if (si > -1) pricePart = pricePart.slice(0, si);
                    var prices = (pricePart.match(/(\d{1,3}(?:,\d{3})*)\s*원/g) || []).map(function (s) { return s.replace(/[^\d]/g, ''); });
                    var price = prices.length ? prices[prices.length - 1] : '';
                    var shipping = '';
                    var shm = text.match(/배송비\s*(\d{1,3}(?:,\d{3})*)\s*원/);
                    if (shm) shipping = shm[1].replace(/,/g, '');
                    else if (/무료\s*배송|배송비\s*무료/.test(text)) shipping = '0';
                    var im = li.querySelector('img');
                    out.push({
                        id: m[1], title: title, price: price,
                        url: a.href, img: im ? (im.src || im.getAttribute('data-src') || '') : '',
                        soldout: /품절|일시품절|SOLD\s*OUT/i.test(text), shipping: shipping,
                        // 공식 스토어라 제조사는 항상 굿스마일컴퍼니(naver API와 동일값). API 실패 시에도 채운다.
                        maker: '굿스마일컴퍼니'
                    });
                });
            }
            return JSON.stringify(out);
            JS;
    }
}
