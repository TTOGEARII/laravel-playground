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
     * 고도몰 리스트/상세도 서버렌더링이라 Selenium 없이 HTTP+DOM 파싱으로 처리한다.
     */
    public function usesHttpFetch(): bool
    {
        return true;
    }

    /**
     * 전량 크롤 카테고리 발견(HTTP). 오버라이드 안 하면 부모의 Selenium 버전을 타므로,
     * HTTP 샵은 반드시 여기서 HTTP+DOM 파싱으로 대체한다(Selenium 세션 안 만듦).
     *
     * @return array<int, string>
     */
    protected function discoverCategoryPaths(): array
    {
        $html = $this->httpGet($this->baseUrl().'/');

        return $html === null ? [] : $this->parseCategoryPaths($html);
    }

    /**
     * 메뉴 HTML 에서 goods_list.php?cateCd= 경로를 수집.
     *
     * @return array<int, string>
     */
    protected function parseCategoryPaths(string $html): array
    {
        $xp = $this->loadXPath($html);
        if ($xp === null) {
            return [];
        }

        $set = [];
        foreach ($xp->query("//a[contains(@href, 'goods_list.php?cateCd=')]") as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            if (preg_match('/cateCd=(\d+)/', $a->getAttribute('href'), $m)) {
                $set['/goods/goods_list.php?cateCd='.$m[1]] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * 고도몰 리스트 HTML → 상품 행 배열(listScript()의 PHP 대응).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseListRows(string $html): array
    {
        $xp = $this->loadXPath($html);
        if ($xp === null) {
            return [];
        }

        $items = $xp->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' item_cont ')]"
            ."[.//a[contains(@href, 'goods_view.php?goodsNo=')]]"
        );
        if ($items === false) {
            return [];
        }

        $rows = [];
        $seen = [];
        foreach ($items as $item) {
            $a = $this->firstNode($xp, ".//a[contains(@href, 'goods_view.php?goodsNo=')]", $item);
            if (! $a instanceof \DOMElement) {
                continue;
            }
            if (! preg_match('/goodsNo=(\d+)/', $a->getAttribute('href'), $m)) {
                continue;
            }
            $id = $m[1];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $nameEl = $this->firstNode($xp, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' item_name ')]", $item);

            $rows[] = [
                'id' => $id,
                'title' => $this->cleanText($nameEl?->textContent),
                'price' => $this->animatePrice($xp, $item),
                'url' => 'goods/goods_view.php?goodsNo='.$id,
                'img' => $this->animateImage($xp, $item),
                'soldout' => $this->animateSoldout($xp, $item),
                'makercode' => '',
            ];
        }

        return $rows;
    }

    /**
     * 고도몰 상세 HTML → {barcode, soldout}. 라벨이 '자체상품코드/상품코드/바코드'인 행의 값에서
     * 8~13자리 숫자(JAN/EAN)를 바코드로 채택한다(동일상품 매칭용 고유값).
     *
     * @return array<string, mixed>|null
     */
    protected function parseDetail(string $html): ?array
    {
        $xp = $this->loadXPath($html);
        if ($xp === null) {
            return null;
        }

        $barcode = '';
        $rows = $xp->query('//table//tr | //dl');
        if ($rows !== false) {
            foreach ($rows as $row) {
                $th = $this->firstNode($xp, './/th | .//dt', $row);
                $td = $this->firstNode($xp, './/td | .//dd', $row);
                if ($th === null || $td === null) {
                    continue;
                }
                $label = preg_replace('/\s+/u', '', $th->textContent) ?? '';
                if (preg_match('/자체상품코드|상품코드|바코드/u', $label)) {
                    $digits = preg_replace('/\D/', '', $td->textContent) ?? '';
                    if (strlen($digits) >= 8 && strlen($digits) <= 13) {
                        $barcode = $digits;
                        break;
                    }
                }
            }
        }

        $soldoutNodes = $xp->query("//*[contains(@class, 'btn_soldout') or contains(@class, 'item_soldout_bg') or contains(@class, 'btn_shop_soldout')] | //img[contains(@alt, '품절')]");
        $soldout = $soldoutNodes !== false && $soldoutNodes->length > 0;

        return ['barcode' => $barcode, 'maker' => '', 'soldout' => $soldout];
    }

    /** 카드 텍스트에서 가격(숫자) 추출. */
    private function animatePrice(\DOMXPath $xp, \DOMNode $item): string
    {
        $priceEl = $this->firstNode($xp, ".//*[contains(@class, 'item_price') or contains(@class, 'item_money_box')]", $item);
        $text = $this->cleanText($priceEl !== null ? $priceEl->textContent : $item->textContent);
        if (preg_match('/(\d{1,3}(?:,\d{3})*)\s*원/u', $text, $m)) {
            return str_replace(',', '', $m[1]);
        }

        return '';
    }

    /** 아이콘/버튼류를 건너뛰고 실제 상품 이미지 src 를 고른다. */
    private function animateImage(\DOMXPath $xp, \DOMNode $item): string
    {
        $imgs = $xp->query('.//img', $item);
        if ($imgs === false) {
            return '';
        }
        foreach ($imgs as $im) {
            if (! $im instanceof \DOMElement) {
                continue;
            }
            $src = $im->getAttribute('src');
            if ($src === '' || preg_match('#/icon/|goods_icon|tokuten|/_btn/|blank|btn_#i', $src)) {
                continue;
            }

            return $src;
        }

        return '';
    }

    /** 품절 판정(godo): item_soldout 클래스(자신/조상) 또는 품절 오버레이/버튼/alt. */
    private function animateSoldout(\DOMXPath $xp, \DOMNode $item): bool
    {
        return $this->firstNode($xp, "ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' item_soldout ')]", $item) !== null
            || $this->firstNode($xp, ".//*[contains(@class, 'item_soldout_bg') or contains(@class, 'btn_shop_soldout') or contains(@class, 'btn_add_soldout')]", $item) !== null
            || $this->firstNode($xp, ".//img[contains(@alt, '품절')]", $item) !== null;
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
