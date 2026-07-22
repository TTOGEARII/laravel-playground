<?php

namespace App\Services\OtakuShop\Crawler\GlobalShops;

use App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;

/**
 * 아미아미(AmiAmi) — 해외관 첫 크롤러.
 *
 * Cloudflare 가 일반 HTTP 를 TLS 지문 레벨에서 403 차단해 Selenium/HTTP 대신
 * Playwright 사이드카(tools/raid-crawler/otaku-amiami.mjs)로 수집한다.
 * 수집 범위(MVP): 예약 가능 상품 × 피규어 카테고리(config otaku-crawler.global.amiami).
 *
 * 매칭 오염 방지 원칙: 해외 상품은 영문 제목이라 제목 기반 매칭(정규화/퍼지)을 신뢰할 수
 * 없다. JAN 바코드('jan_' 접두, 목록 API 에 포함)만 매칭 키로 태우고, JAN 없으면 스킵한다.
 * JAN 으로 국내 상품과 묶이면 그 상품에 JPY 오퍼가 붙는다(교차 가격비교 — 환산은 표시층).
 */
class AmiamiCrawler implements ShopCrawlerInterface
{
    public const SHOP_CODE = 'amiami';

    public function __construct(
        private OtakuSidecarRunner $runner,
    ) {}

    public function getShopCode(): string
    {
        return self::SHOP_CODE;
    }

    /**
     * {@inheritdoc}
     */
    public function crawlProducts(): array
    {
        $cfg = (array) config('otaku-crawler.global.amiami', []);
        $filters = collect((array) ($cfg['filters'] ?? []))
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode(',');

        $payload = $this->runner->run(
            script: (string) config('otaku-crawler.global.script'),
            args: array_values(array_filter([
                '--base='.($cfg['base'] ?? 'https://www.amiami.com/eng/'),
                '--api-base='.($cfg['api_base'] ?? 'https://api.amiami.com/api/v1.0'),
                '--categories='.implode(',', (array) ($cfg['categories'] ?? [])),
                $filters !== '' ? '--filters='.$filters : null,
                '--page-size='.(int) ($cfg['page_size'] ?? 50),
                '--max-pages='.(int) ($cfg['max_pages'] ?? 0),
                '--delay-ms='.(int) ($cfg['delay_ms'] ?? 1500),
                '--retries='.(int) ($cfg['retries'] ?? 3),
            ])),
            timeoutSec: (int) config('otaku-crawler.global.timeout', 1800),
        );

        if ($payload === null) {
            return []; // 사이드카 실패는 러너가 이미 로그 — 빈 결과 폴백(커맨드가 0건 가드)
        }

        $dtos = [];
        foreach ($payload['items'] as $item) {
            $dto = $this->toDto((array) $item);
            if ($dto !== null) {
                $dtos[] = $dto;
            }
        }

        return $dtos;
    }

    /**
     * 사이드카 아이템 1건 → DTO. 수집 불가(필수값·JAN 없음·중고)면 null.
     *
     * @param  array<string, mixed>  $item
     */
    private function toDto(array $item): ?CrawledProductDto
    {
        $gcode = trim((string) ($item['gcode'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $price = (float) ($item['price_jpy'] ?? 0);
        if ($gcode === '' || $title === '' || $price <= 0) {
            return null;
        }

        // 중고(-R 접미) 방어: 사이드카가 걸러 보내지만 계약 변화·재사용 대비 이중 가드.
        // 중고가는 신품 가격비교를 오염시키므로(케이스=본체 오염과 같은 결) 절대 적재하지 않는다.
        if (str_ends_with(strtoupper($gcode), '-R')) {
            return null;
        }

        // JAN 없는 상품은 스킵 — 영문 제목이라 정규화/퍼지 매칭을 신뢰할 수 없어
        // JAN 바코드만 매칭 키로 쓴다(기존 'jan_' 접두 컨벤션과 동일, 8~13자리 바코드만 인정).
        $jan = preg_replace('/\D/', '', (string) ($item['jancode'] ?? '')) ?? '';
        $janLength = strlen($jan);
        if ($janLength < 8 || $janLength > 13) {
            return null;
        }

        $imageUrl = trim((string) ($item['image_url'] ?? ''));

        return new CrawledProductDto(
            shopCode: self::SHOP_CODE,
            externalId: $gcode,
            title: $title,
            subtitle: null,
            brandLabel: null,
            price: $price,               // min_price = 실판매가(세후 JPY 원가 그대로 저장)
            productUrl: 'https://www.amiami.com/eng/detail?gcode='.rawurlencode($gcode),
            categoryCode: 'figure',      // 수집 대상이 피규어 카테고리(459·1298)뿐이라 고정
            releaseDate: $this->parseReleaseDate((string) ($item['release_date'] ?? '')),
            imageUrl: $imageUrl !== '' ? $imageUrl : null,
            available: (bool) ($item['available'] ?? true),
            makerCode: 'jan_'.$jan,
            currency: 'JPY',
        );
    }

    /**
     * 아미아미 releasedate 를 Y-m-d 로 정규화. ISO(YYYY-MM[-DD]) 우선,
     * 영문 월 표기("late Jan-2027" 등)는 월 첫날로 폴백. 해석 불가면 null(방어).
     */
    private function parseReleaseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(\d{4})-(\d{2})(?:-(\d{2}))?/', $raw, $m)) {
            $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;

            return checkdate((int) $m[2], $day, (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], $day)
                : null;
        }

        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        if (preg_match('/([a-z]{3})[a-z]*[\s.\-]*(\d{4})/i', $raw, $m)) {
            $month = $months[strtolower($m[1])] ?? null;

            return $month !== null ? sprintf('%04d-%02d-01', (int) $m[2], $month) : null;
        }

        return null;
    }
}
