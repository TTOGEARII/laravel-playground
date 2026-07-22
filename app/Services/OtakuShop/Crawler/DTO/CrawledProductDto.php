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
        public ?string $productUrl,
        public ?string $categoryCode,
        public ?string $releaseDate = null,
        public ?float $shippingFee = null,
        public ?string $imageUrl = null,
        public bool $available = true,
        public ?string $makerCode = null,
        public ?string $maker = null,
        // 가격 통화(ISO 4217). 국내 샵은 기본 KRW, 해외 샵(아미아미 등)은 현지 통화 원가를 그대로 담는다.
        // 환산은 크롤·저장 단계가 아니라 표시층(최저가 비교는 CrawlSyncService 환산 비교) 몫이다.
        public string $currency = 'KRW',
    ) {}
}
