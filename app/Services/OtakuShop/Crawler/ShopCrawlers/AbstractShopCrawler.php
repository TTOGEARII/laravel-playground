<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

use App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface;
use App\Services\OtakuShop\Crawler\CrawlerDriver;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use App\Services\OtakuShop\Crawler\ProductNormalizer;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Facades\Log;

/**
 * 쇼핑몰 크롤러 베이스.
 *
 * 동작 개요:
 *   1) config(otaku-crawler.listings.{shopCode}) 의 카테고리 리스트 페이지들을 순회한다.
 *   2) 각 페이지에서 listScript()(브라우저에서 실행되는 JS)를 executeScript 로 한 번에 돌려
 *      상품 배열(JSON)을 받는다. → 셀렉터마다 Selenium 왕복하지 않아 빠르고 견고하다.
 *   3) 받은 행을 CrawledProductDto 로 변환하고, 제목 키워드로 카테고리를 보정한다.
 *
 * 새 쇼핑몰 추가 시: getShopCode()/baseUrl()/listScript() 만 구현하면 된다.
 */
abstract class AbstractShopCrawler implements ShopCrawlerInterface
{
    /** 전량 모드(카테고리 자동 발견·끝 페이지까지·긴 딜레이). */
    protected bool $fullMode = false;

    /** 현재 세션에서 로드한 페이지 수(세션 재생성 주기 판단용). */
    private int $pageLoads = 0;

    public function __construct(
        protected CrawlerDriver $driver
    ) {}

    /**
     * 쇼핑몰 기준 URL (스킴+호스트, 끝 슬래시 없이).
     */
    abstract protected function baseUrl(): string;

    /**
     * 리스트 페이지에서 상품을 추출해 JSON 문자열을 반환하는 브라우저 JS.
     * 반환 형식: '[{"id","title","price","url","img"}, ...]'
     */
    abstract protected function listScript(): string;

    /**
     * 전량 크롤 모드 활성화 (최초 1회용).
     */
    public function enableFullMode(): static
    {
        $this->fullMode = true;

        return $this;
    }

    /**
     * 새 페이지를 로드하기 직전 호출. recycle_after_pages 마다 세션을 새로 만들어
     * 장시간 단일 세션의 렌더러 degradation(간헐 타임아웃)을 방지한다.
     * 재생성은 페이지 경계에서만 일어나므로 get()→executeScript() 한 쌍은 절대 분리되지 않는다.
     */
    private function driverForNextPage(): RemoteWebDriver
    {
        $threshold = max(0, (int) config('otaku-crawler.crawl.recycle_after_pages', 80));
        if ($threshold > 0 && $this->pageLoads >= $threshold) {
            $this->driver->recycle();
            $this->pageLoads = 0;
        }
        $this->pageLoads++;

        return $this->driver->getDriver();
    }

    /**
     * {@inheritdoc}
     */
    public function crawlProducts(): array
    {
        $seen = [];
        $all = [];

        foreach ($this->listingTargets() as $target) {
            $path = (string) ($target['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $category = $target['category'] ?? null;
            $pages = max(1, (int) ($target['pages'] ?? 1));

            for ($page = 1; $page <= $pages; $page++) {
                $this->delayBetweenRequests();
                $url = $this->baseUrl().'/'.ltrim($path, '/');
                if ($page > 1) {
                    $url = $this->pagedUrl($url, $page);
                }

                $rows = $this->fetchPage($url);
                if ($rows === []) {
                    break; // 더 이상 상품이 없으면 다음 페이지를 요청하지 않는다.
                }

                foreach ($rows as $row) {
                    $dto = $this->toDto($row, $category);
                    if ($dto === null) {
                        continue;
                    }
                    $key = $this->getShopCode().':'.$dto->externalId;
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $all[] = $dto;
                    }
                }
            }
        }

        // 옵트인: 상세 페이지를 한 번 더 열어 바코드(고유값)·제조사·품절을 보강한다.
        // 전역 flag 또는 샵별 화이트리스트(fetch_detail_shops) 중 하나라도 켜지면 수행.
        $detailShops = config('otaku-crawler.crawl.fetch_detail_shops', []);
        if (config('otaku-crawler.crawl.fetch_detail', false) || in_array($this->getShopCode(), $detailShops, true)) {
            $this->enrichWithDetails($all);
        }

        return $all;
    }

    /**
     * 수집한 상품들의 상세 페이지를 열어 바코드/제조사/품절을 보강한다.
     * 상품마다 요청이 1건씩 추가되므로 config(crawl.detail) 로 딜레이·상한을 둔다.
     *
     * @param  array<int, CrawledProductDto>  $dtos
     */
    private function enrichWithDetails(array $dtos): void
    {
        $delayMs = (int) config('otaku-crawler.crawl.detail.delay_ms', 1200);
        $max = (int) config('otaku-crawler.crawl.detail.max_products', 0);
        $script = $this->detailScript();

        $count = 0;
        foreach ($dtos as $dto) {
            if ($max > 0 && $count >= $max) {
                break;
            }
            if (! $dto->productUrl) {
                continue;
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $count++;

            $info = $this->fetchDetail($dto->productUrl, $script);
            if ($info === null) {
                continue;
            }

            // 바코드(JAN/EAN/ISBN, 8~13자리)만 고유값으로 채택해 잘못된 내부코드 매칭을 막는다.
            $barcode = preg_replace('/\D/', '', (string) ($info['barcode'] ?? '')) ?? '';
            if ($barcode !== '' && strlen($barcode) >= 8 && strlen($barcode) <= 13) {
                $dto->makerCode = 'jan_'.$barcode;
            }

            $maker = trim((string) ($info['maker'] ?? ''));
            if ($maker !== '') {
                $dto->maker = mb_substr($maker, 0, 120);
            }

            // 상세 품절은 재고 상태를 '추가로' 끌어내리기만 한다(리스트에서 이미 품절이면 유지).
            if (! empty($info['soldout'])) {
                $dto->available = false;
            }
        }
    }

    /**
     * 상세 페이지 하나를 열어 detailScript() 로 {barcode, maker, soldout} 를 받아온다.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDetail(string $url, string $script): ?array
    {
        $driver = $this->driverForNextPage();

        try {
            $driver->get($url);
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: detail load failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }

        usleep(600 * 1000);

        try {
            $raw = $driver->executeScript($script);
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: detail script failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }

        $data = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($data) ? $data : null;
    }

    /**
     * 상세 페이지에서 바코드/제조사/품절을 추출하는 브라우저 JS.
     * cafe24·godo 모두 "라벨(th/dt) → 값(td/dd)" 행 구조라 라벨 키워드 매칭 하나로 공용 처리한다.
     * 실측 확인: 따빼몰 JAN코드, 애니메이트 자체상품코드(=바코드), 도키도키굿즈는 미입력→빈값.
     */
    protected function detailScript(): string
    {
        return <<<'JS'
            function findByLabels(names) {
                const cells = document.querySelectorAll('th, dt');
                for (const c of cells) {
                    const t = c.textContent.replace(/\s+/g, ' ').trim();
                    for (const n of names) {
                        if (t === n || t.replace(/\s/g, '') === n.replace(/\s/g, '')) {
                            const row = c.closest('tr');
                            if (row) { const td = row.querySelector('td'); if (td) return td.textContent.replace(/\s+/g, ' ').trim(); }
                            const dd = c.nextElementSibling;
                            if (dd) return dd.textContent.replace(/\s+/g, ' ').trim();
                        }
                    }
                }
                return '';
            }
            const barcodeRaw = findByLabels(['JAN코드', '자체상품코드', '바코드', '상품코드', '상품번호']);
            const bm = barcodeRaw.match(/(\d{8,13})/);
            const barcode = bm ? bm[1] : '';
            const maker = findByLabels(['제조사']);
            // 품절: cafe24 품절 아이콘(실측 확인) 또는 godo 품절 버튼(스킨 CSS 확인 클래스).
            // 광범위한 [class*=soldout]는 상세의 연관상품 위젯 등에서 오탐이 나므로 쓰지 않는다.
            const soldout = !!document.querySelector('img[alt*="품절"], img[src*="soldout" i], .btnSoldOut, .btn_shop_soldout, .btn_add_soldout');
            return JSON.stringify({ barcode, maker, soldout });
            JS;
    }

    /**
     * 크롤 대상 리스트 목록. 전량 모드면 사이트 카테고리 메뉴에서 자동 발견하고,
     * 아니면 config 의 샵별 지정 목록을 쓴다.
     *
     * @return array<int, array{path: string, category?: string|null, pages?: int}>
     */
    protected function listingTargets(): array
    {
        if ($this->fullMode) {
            $discovered = $this->discoverListingTargets();
            if ($discovered !== []) {
                return $discovered;
            }
        }

        return config('otaku-crawler.listings.'.$this->getShopCode(), []);
    }

    /**
     * 전량 크롤: 사이트 메뉴에서 모든 상품 리스트(카테고리) 경로를 발견한다.
     * 카테고리는 null 로 두고 제목 키워드로 보정하며, 페이지는 끝까지(max_pages) 돈다.
     *
     * @return array<int, array{path: string, category: null, pages: int}>
     */
    protected function discoverListingTargets(): array
    {
        $script = $this->categoryDiscoveryScript();
        if ($script === null) {
            return [];
        }

        try {
            $driver = $this->driverForNextPage();
            $driver->get($this->baseUrl().'/');
            usleep(800 * 1000);
            $raw = $driver->executeScript($script);
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: category discovery failed', [
                'shop' => $this->getShopCode(),
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $paths = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($paths)) {
            return [];
        }

        $maxPages = max(1, (int) config('otaku-crawler.crawl.full.max_pages', 100));
        $targets = [];
        foreach (array_unique($paths) as $path) {
            if (is_string($path) && $path !== '') {
                $targets[] = ['path' => $path, 'category' => null, 'pages' => $maxPages];
            }
        }

        return $targets;
    }

    /**
     * 카테고리 발견용 브라우저 JS (리스트 경로 배열 JSON 반환). 없으면 null → config 사용.
     */
    protected function categoryDiscoveryScript(): ?string
    {
        return null;
    }

    /**
     * 한 리스트 페이지를 열고 listScript() 로 상품 배열을 받아온다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPage(string $url): array
    {
        $driver = $this->driverForNextPage();

        try {
            $driver->get($url);
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: page load failed', ['url' => $url, 'message' => $e->getMessage()]);

            return [];
        }

        // 지연 로딩(이미지/상품) 렌더 여유.
        usleep(800 * 1000);

        try {
            $raw = $driver->executeScript($this->listScript());
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: list script failed', ['url' => $url, 'message' => $e->getMessage()]);

            return [];
        }

        $rows = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($rows) ? $rows : [];
    }

    /**
     * 한 행(JS 결과)을 DTO 로 변환. 필수값이 없으면 null.
     *
     * @param  array<string, mixed>  $row
     */
    private function toDto(array $row, ?string $fallbackCategory): ?CrawledProductDto
    {
        $externalId = trim((string) ($row['id'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        $price = (float) ($row['price'] ?? 0);

        if ($externalId === '' || $title === '' || $price <= 0) {
            return null;
        }

        // 제외 키워드(예: '잔금결제')가 제목에 있으면 수집하지 않는다(예약 잔금 결제 전용 상품 등).
        if ($this->isExcludedTitle($title)) {
            return null;
        }

        // 품절 신호(soldout)는 리스트 카드에서 읽어온다. 없으면 판매중으로 본다.
        $available = ! (bool) ($row['soldout'] ?? false);
        // 마크업이 노출하는 상품 고유값(자체 품번/모델명 등). 보통은 비어 있고,
        // 그 경우 CrawlSyncService 가 제목에서 JAN/품번을 추출해 보강한다.
        $makerCode = trim((string) ($row['makercode'] ?? ''));
        // 리스트 spec에서 읽은 제조사·발매(있으면). 발매 필드는 전용값이라 키워드 없이 파싱.
        $maker = trim((string) ($row['maker'] ?? ''));
        $releaseRaw = trim((string) ($row['release'] ?? ''));
        $releaseDate = $releaseRaw !== ''
            ? $this->normalizer()->parseReleaseFromText($releaseRaw, requireKeyword: false)
            : null;
        // 배송비: 리스트 카드가 노출하면(예: 굿스마일 네이버) 채운다. 없으면 null.
        $shippingRaw = trim((string) ($row['shipping'] ?? ''));
        $shippingFee = ($shippingRaw !== '' && is_numeric($shippingRaw)) ? (float) $shippingRaw : null;

        return new CrawledProductDto(
            shopCode: $this->getShopCode(),
            externalId: $externalId,
            title: $title,
            subtitle: null,
            brandLabel: null,  // cafe24 '브랜드'와 매칭키가 얽혀 비워둔다(제조사는 maker로 저장).
            price: $price,
            currency: 'KRW',
            productUrl: $this->resolveUrl((string) ($row['url'] ?? '')),
            categoryCode: $this->refineCategory($title, $fallbackCategory),
            releaseDate: $releaseDate,
            shippingFee: $shippingFee,
            imageUrl: $this->resolveImage((string) ($row['img'] ?? '')),
            available: $available,
            makerCode: $makerCode !== '' ? $makerCode : null,
            maker: $maker !== '' ? mb_substr($maker, 0, 120) : null,
        );
    }

    /** 제목에 config(exclude_title_keywords) 키워드가 포함되면 수집 제외. */
    protected function isExcludedTitle(string $title): bool
    {
        foreach (config('otaku-crawler.exclude_title_keywords', []) as $keyword) {
            if ($keyword !== '' && mb_strpos($title, (string) $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private ?ProductNormalizer $normalizerInstance = null;

    /** 발매일 파싱 등에 쓰는 정규화 서비스(지연 resolve). */
    private function normalizer(): ProductNormalizer
    {
        return $this->normalizerInstance ??= app(ProductNormalizer::class);
    }

    /**
     * 페이지네이션 URL (cafe24/고도몰 모두 ?page=N / &page=N 지원).
     */
    protected function pagedUrl(string $url, int $page): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'page='.$page;
    }

    /**
     * 상대/상위(../) 경로를 절대 URL 로.
     */
    protected function resolveUrl(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return $this->baseUrl();
        }
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        $href = preg_replace('#^(\.\./)+#', '', $href) ?? $href;

        return $this->baseUrl().'/'.ltrim($href, '/');
    }

    /**
     * 이미지 src 정규화 (//host → https, 상대경로 → 절대).
     */
    protected function resolveImage(string $src): ?string
    {
        $src = trim($src);
        if ($src === '') {
            return null;
        }
        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }
        if (str_starts_with($src, 'http')) {
            return $src;
        }

        return $this->baseUrl().'/'.ltrim($src, '/');
    }

    /**
     * 제목 키워드로 공통 카테고리 보정. 매칭 없으면 fallback(없으면 'other').
     */
    protected function refineCategory(string $title, ?string $fallback): ?string
    {
        $lower = mb_strtolower($title);
        foreach (config('otaku-crawler.category_keywords', []) as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($lower, mb_strtolower($keyword))) {
                    return $code;
                }
            }
        }

        return $fallback ?? 'other';
    }

    /**
     * 요청 사이 딜레이로 트래픽 급증/차단 방지. 전량 모드면 더 보수적으로 쉰다.
     */
    protected function delayBetweenRequests(): void
    {
        $ms = $this->fullMode
            ? (int) config('otaku-crawler.crawl.full.delay_ms_between_requests', 3000)
            : (int) config('otaku-crawler.crawl.delay_ms_between_requests', 1500);

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
