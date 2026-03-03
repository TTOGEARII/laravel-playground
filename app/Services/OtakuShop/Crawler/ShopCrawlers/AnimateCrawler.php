<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use Facebook\WebDriver\WebDriverBy;

/**
 * 애니메이트코리아 온라인샵 (animate-onlineshop.co.kr) 크롤러.
 * 상품 링크 goods_view.php?goodsNo=, 제목=링크텍스트, 가격 "N,NNN 원" 구조.
 */
class AnimateCrawler extends AbstractShopCrawler
{
    private const BASE_URL = 'https://www.animate-onlineshop.co.kr';

    public function getShopCode(): string
    {
        return 'animate';
    }

    protected function getListingUrl(): string
    {
        return self::BASE_URL . '/';
    }

    /**
     * @return array<int, CrawledProductDto>
     */
    protected function parseProductItems(): array
    {
        $seen = [];
        $items = [];
        $this->delayBetweenRequests();
        $this->driver->get($this->getListingUrl());
        $this->waitForProducts();

        $elements = $this->driver->findElements(WebDriverBy::cssSelector(
            'a[href*="goods_view.php"][href*="goodsNo="]'
        ));
        foreach ($elements as $el) {
            $dto = $this->parseOneElement($el);
            if ($dto !== null && ! isset($seen[$dto->externalId])) {
                $seen[$dto->externalId] = true;
                $items[] = $dto;
            }
        }

        return $items;
    }

    private function waitForProducts(): void
    {
        try {
            $this->driver->findElement(WebDriverBy::cssSelector('a[href*="goods_view.php"]'));
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
            $externalId = $this->parseGoodsNo($href);
            if ($externalId === '') {
                return null;
            }
            $linkText = $element->getText();
            $title = trim($linkText);
            if ($title === '') {
                return null;
            }
            $blockText = $this->getBlockText($element);
            $price = $this->extractPrice($blockText !== '' ? $blockText : $linkText);
            if ($price <= 0) {
                return null;
            }
            $productUrl = str_contains($href, 'http') ? $href : (self::BASE_URL . '/' . ltrim($href, '/'));
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
                categoryCode: $this->guessCategory($title),
                releaseDate: null,
                shippingFee: null,
                imageUrl: $imageUrl,
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseGoodsNo(string $url): string
    {
        if (preg_match('/goodsNo=(\d+)/', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    private function getBlockText($linkElement): string
    {
        try {
            $parent = $linkElement->findElement(WebDriverBy::xpath('./ancestor::li[1]'));
            return $parent->getText();
        } catch (\Throwable) {
            try {
                $parent = $linkElement->findElement(WebDriverBy::xpath('./ancestor::*[contains(@class,"item") or contains(@class,"goods") or contains(@class,"product")][1]'));
                return $parent->getText();
            } catch (\Throwable) {
                return '';
            }
        }
    }

    private function extractPrice(string $text): float
    {
        if (preg_match('/(\d{1,3}(?:,\d{3})*)\s*원/u', $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        return 0.0;
    }

    private function guessCategory(string $title): string
    {
        if (preg_match('/【(굿즈|서적|음악|AV|국내서적|해외서적)/u', $title)) {
            return 'goods';
        }
        if (mb_strpos($title, '굿즈') !== false || mb_strpos($title, '아크릴') !== false || mb_strpos($title, '뱃지') !== false) {
            return 'goods';
        }
        return 'goods';
    }

    private function extractImageUrl($linkElement): ?string
    {
        try {
            try {
                $parent = $linkElement->findElement(WebDriverBy::xpath('./ancestor::li[1]'));
            } catch (\Throwable) {
                $parent = $linkElement;
            }

            try {
                $img = $linkElement->findElement(WebDriverBy::cssSelector('img'));
            } catch (\Throwable) {
                $img = $parent->findElement(WebDriverBy::cssSelector('img'));
            }

            $src = trim((string) $img->getAttribute('src'));
            if ($src === '') {
                return null;
            }
            if (str_starts_with($src, '//')) {
                return 'https:' . $src;
            }
            if (! str_starts_with($src, 'http')) {
                return self::BASE_URL . '/' . ltrim($src, '/');
            }

            return $src;
        } catch (\Throwable) {
            return null;
        }
    }
}
