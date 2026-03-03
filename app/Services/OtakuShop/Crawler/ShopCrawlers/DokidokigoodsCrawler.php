<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use Facebook\WebDriver\WebDriverBy;

/**
 * 도키도키굿즈 (dokidokigoods.co.kr) 크롤러.
 * 카페24 기반: 상품 링크 product/detail.html?product_no=, "상품명 :", "판매가 : N원" 구조.
 */
class DokidokigoodsCrawler extends AbstractShopCrawler
{
    private const BASE_URL = 'https://dokidokigoods.co.kr';

    public function getShopCode(): string
    {
        return 'dokidokigoods';
    }

    protected function getListingUrl(): string
    {
        return self::BASE_URL . '/';
    }

    /**
     * 메인 + 신상품 목록 URL 병합 수집 (한 페이지만).
     */
    protected function getListingUrls(): array
    {
        return [
            self::BASE_URL . '/',
            self::BASE_URL . '/product/list.html?cate_no=28', // 신상품
            self::BASE_URL . '/product/list.html?cate_no=202', // 입고완료
        ];
    }

    /**
     * @return array<int, CrawledProductDto>
     */
    protected function parseProductItems(): array
    {
        $seen = [];
        $items = [];
        $urls = $this->getListingUrls();

        foreach ($urls as $url) {
            $this->delayBetweenRequests();
            $this->driver->get($url);
            $this->waitForProducts();
            $elements = $this->driver->findElements(WebDriverBy::cssSelector(
                'a[href*="product/detail.html"][href*="product_no="]'
            ));
            foreach ($elements as $el) {
                $dto = $this->parseOneElement($el);
                if ($dto !== null && ! isset($seen[$dto->externalId])) {
                    $seen[$dto->externalId] = true;
                    $items[] = $dto;
                }
            }
        }

        return $items;
    }

    private function waitForProducts(): void
    {
        try {
            $this->driver->findElement(WebDriverBy::cssSelector('a[href*="product/detail.html"]'));
        } catch (\Throwable) {
            // ignore
        }
    }

    private function parseOneElement($element): ?CrawledProductDto
    {
        try {
            $href = trim($element->getAttribute('href') ?? '');
            if ($href === '') {
                return null;
            }
            $externalId = $this->parseProductNo($href);
            if ($externalId === '') {
                return null;
            }
            $linkText = $element->getText();
            $title = $this->extractTitleFromLink($linkText);
            $blockText = $this->getBlockText($element);
            $price = $this->extractPrice($blockText !== '' ? $blockText : $linkText);
            if ($title === '' || $price <= 0) {
                return null;
            }
            $productUrl = str_contains($href, 'http') ? $href : (self::BASE_URL . $href);
            $imageUrl = $this->extractImageUrl($element);

            return new CrawledProductDto(
                shopCode: $this->getShopCode(),
                externalId: $externalId,
                title: $title,
                subtitle: null,
                brandLabel: null,
                price: $price,
                currency: 'KRW',
                productUrl: $productUrl,
                categoryCode: 'goods',
                releaseDate: null,
                shippingFee: null,
                imageUrl: $imageUrl,
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseProductNo(string $url): string
    {
        if (preg_match('/product_no=(\d+)/', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractTitleFromLink(string $text): string
    {
        $t = trim($text);
        if (preg_match('/상품명\s*:\s*(.+)/u', $t, $m)) {
            return trim($m[1]);
        }
        $lines = array_filter(array_map('trim', explode("\n", $t)));
        foreach ($lines as $line) {
            if (preg_match('/\d{1,3}(,\d{3})*\s*원/u', $line)) {
                continue;
            }
            if (mb_strlen($line) >= 2) {
                return $line;
            }
        }
        return $t ?: '';
    }

    private function getBlockText($linkElement): string
    {
        try {
            $parent = $linkElement->findElement(WebDriverBy::xpath('./ancestor::li[1]'));
            return $parent->getText();
        } catch (\Throwable) {
            try {
                $parent = $linkElement->findElement(WebDriverBy::xpath('./ancestor::*[contains(@class,"item") or contains(@class,"prd") or contains(@class,"product")][1]'));
                return $parent->getText();
            } catch (\Throwable) {
                return '';
            }
        }
    }

    private function extractPrice(string $text): float
    {
        if (preg_match('/판매가\s*:\s*(\d{1,3}(?:,\d{3})*)\s*원/u', $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        if (preg_match('/(\d{1,3}(?:,\d{3})*)\s*원/u', $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        return 0.0;
    }

    private function extractImageUrl($linkElement): ?string
    {
        try {
            // 보통 li 안에 대표 이미지가 img 태그로 들어가므로 상위 li 기준으로 찾는다.
            try {
                $parent = $linkElement->findElement(WebDriverBy::xpath('./ancestor::li[1]'));
            } catch (\Throwable) {
                $parent = $linkElement;
            }

            $img = $parent->findElement(WebDriverBy::cssSelector('img'));
            $src = trim((string) $img->getAttribute('src'));
            if ($src === '') {
                return null;
            }
            if (str_starts_with($src, '//')) {
                return 'https:' . $src;
            }
            if (! str_starts_with($src, 'http')) {
                return self::BASE_URL . $src;
            }

            return $src;
        } catch (\Throwable) {
            return null;
        }
    }
}
