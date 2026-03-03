<?php

namespace App\Services\OtakuShop\Crawler;

/**
 * 동일 상품 그룹핑용 제목 정규화.
 */
class ProductNormalizer
{
    public function __construct(
        private int $titleMinLength = 5,
        private array $stripPatterns = [],
    ) {
        $this->stripPatterns = $this->stripPatterns ?: config('otaku-crawler.product_match.strip_patterns', []);
        $this->titleMinLength = $this->titleMinLength ?: config('otaku-crawler.product_match.title_min_length', 5);
    }

    /**
     * 상품 정규화 키 (동일 상품 매칭용).
     */
    public function normalizeKey(string $title, ?string $brandLabel = null): string
    {
        $t = $this->normalizeTitle($title);
        $b = $brandLabel !== null && $brandLabel !== '' ? mb_strtolower(trim($brandLabel)) : '';
        $raw = $t . '|' . $b;

        return 'pr_' . md5($raw);
    }

    /**
     * 제목만 정규화 (표시/검색용 아님, 매칭용).
     */
    public function normalizeTitle(string $title): string
    {
        $s = trim($title);
        foreach ($this->stripPatterns as $pattern) {
            $s = preg_replace($pattern, '', $s);
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        if (mb_strlen($s) < $this->titleMinLength) {
            return $title;
        }

        return $s;
    }
}
