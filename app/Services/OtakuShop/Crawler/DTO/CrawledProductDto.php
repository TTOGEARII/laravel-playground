<?php

namespace App\Services\OtakuShop\Crawler\DTO;

/**
 * 크롤링된 단일 상품 (샵별 원본 데이터).
 */
final class CrawledProductDto
{
    public function __construct(
        public string $shopCode,
        public string $externalId,
        public string $title,
        public ?string $subtitle,
        public ?string $brandLabel,
        public float $price,
        public string $currency,
        public ?string $productUrl,
        public ?string $categoryCode,
        public ?string $releaseDate = null,
        public ?float $shippingFee = null,
        public ?string $imageUrl = null,
    ) {}
}
