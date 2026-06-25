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

    /**
     * cafe24 리스트 페이지는 서버렌더링이라 Selenium 없이 HTTP+DOM 파싱으로 처리한다.
     */
    protected function usesHttpFetch(): bool
    {
        return true;
    }

    /**
     * 전량 크롤 카테고리 발견(HTTP): 메뉴 HTML 에서 상품 리스트 경로를 수집한다.
     *
     * @return array<int, string>
     */
    protected function discoverCategoryPaths(): array
    {
        $html = $this->httpGet($this->baseUrl().'/');

        return $html === null ? [] : $this->parseCategoryPaths($html);
    }

    /**
     * cafe24 메뉴 HTML 에서 상품 리스트 경로(product/list.html?cate_no=)를 수집.
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
        foreach ($xp->query("//a[contains(@href, 'product/list.html?cate_no=')]") as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            if (preg_match('/cate_no=(\d+)/', $a->getAttribute('href'), $m)) {
                $set['/product/list.html?cate_no='.$m[1]] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * 표준 cafe24 리스트 HTML → 상품 행 배열(listScript()의 PHP 대응).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseListRows(string $html): array
    {
        $xp = $this->loadXPath($html);
        if ($xp === null) {
            return [];
        }

        $lis = $xp->query(
            "//ul[contains(concat(' ', normalize-space(@class), ' '), ' prdList ')]"
            ."/li[starts-with(@id, 'anchorBoxId_')"
            ." or contains(concat(' ', normalize-space(@class), ' '), ' item ')"
            ." or contains(@class, 'xans-record-')]"
        );
        if ($lis === false) {
            return [];
        }

        $rows = [];
        foreach ($lis as $li) {
            $a = $this->firstNode($xp, ".//a[contains(@href, 'product/detail.html') and contains(@href, 'product_no=')]", $li);
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            if (! preg_match('/product_no=(\d+)/', $href, $m)) {
                continue;
            }

            $rows[] = [
                'id' => $m[1],
                'title' => $this->extractTitle($xp, $li),
                'price' => $this->extractPrice($li),
                'url' => $href,
                'img' => $this->extractImage($xp, $li),
                'soldout' => $this->extractSoldout($xp, $li),
                'maker' => $this->extractSpec($xp, $li, ['제조사', '브랜드']),
                'release' => $this->extractSpec($xp, $li, ['발매', '발매일', '출시', '출시일']),
            ];
        }

        return $rows;
    }

    /**
     * 카드에서 상품명 추출(숨김 "상품명 :" 접두사 제거, 없으면 이미지 alt 폴백).
     */
    protected function extractTitle(\DOMXPath $xp, \DOMNode $li): string
    {
        $nameEl = $this->firstNode(
            $xp,
            ".//p[contains(concat(' ', normalize-space(@class), ' '), ' name ')]/a"
            ." | .//*[contains(concat(' ', normalize-space(@class), ' '), ' name ')]/a"
            ." | .//*[contains(concat(' ', normalize-space(@class), ' '), ' name ')]",
            $li
        );
        $title = $nameEl ? $this->cleanText(preg_replace('/상품명\s*:/u', '', $nameEl->textContent)) : '';
        $title = ltrim($title, ": \t");

        if ($title === '') {
            $altImg = $this->firstNode($xp, ".//p[contains(@class, 'prdImg')]//img | .//img[starts-with(@id, 'eListPrdImage')] | .//img[@alt]", $li);
            if ($altImg instanceof \DOMElement) {
                $title = $this->cleanText($altImg->getAttribute('alt'));
            }
        }

        return $title;
    }

    /**
     * 카드 텍스트에서 가격(숫자) 추출. "판매가 : N원" 우선, 없으면 첫 "N,NNN원".
     */
    protected function extractPrice(\DOMNode $li): string
    {
        $text = $this->cleanText($li->textContent);
        if (preg_match('/판매가\s*:?\s*(\d{1,3}(?:,\d{3})*)\s*원/u', $text, $m)) {
            return str_replace(',', '', $m[1]);
        }
        if (preg_match('/(\d{1,3}(?:,\d{3})+)\s*원/u', $text, $m)) {
            return str_replace(',', '', $m[1]);
        }

        return '';
    }

    /**
     * 상품 이미지 src 추출(/web/product/ 포함, lazy-load 속성도 확인).
     */
    protected function extractImage(\DOMXPath $xp, \DOMNode $li): string
    {
        $imgs = $xp->query('.//img', $li);
        if ($imgs === false) {
            return '';
        }
        foreach ($imgs as $im) {
            if (! $im instanceof \DOMElement) {
                continue;
            }
            $src = $im->getAttribute('src');
            if ($src === '') {
                $src = $im->getAttribute('ec-data-src') ?: $im->getAttribute('data-src');
            }
            if ($src !== '' && str_contains($src, '/web/product/')) {
                return $src;
            }
        }

        return '';
    }

    /**
     * 품절 여부(cafe24 품절 아이콘: src 에 soldout(대소문자 무시) 또는 alt 에 품절).
     */
    protected function extractSoldout(\DOMXPath $xp, \DOMNode $li): bool
    {
        return $this->firstNode(
            $xp,
            ".//img[contains(translate(@src, 'SOLDUT', 'soldut'), 'soldout') or contains(@alt, '품절')]",
            $li
        ) !== null;
    }

    /**
     * 카드의 상품정보 행(라벨:값)에서 주어진 라벨 중 하나의 값을 반환(제조사/발매 등).
     *
     * @param  array<int, string>  $labels
     */
    protected function extractSpec(\DOMXPath $xp, \DOMNode $li, array $labels): string
    {
        $rows = $xp->query(
            ".//*[contains(@class, 'spec')]//li"
            ." | .//*[contains(@class, 'description')]//li"
            ." | .//*[contains(@class, 'xans-record-')]//li",
            $li
        );
        if ($rows === false) {
            return '';
        }
        foreach ($rows as $r) {
            $t = $this->cleanText($r->textContent);
            $ci = mb_strpos($t, ':');
            if ($ci === false || $ci <= 0 || mb_strlen($t) >= 70) {
                continue;
            }
            $key = trim(mb_substr($t, 0, $ci));
            $value = trim(mb_substr($t, $ci + 1));
            if ($value !== '' && in_array($key, $labels, true)) {
                return $value;
            }
        }

        return '';
    }
}
