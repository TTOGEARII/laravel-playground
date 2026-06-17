<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

use App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
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

    public function __construct(
        protected RemoteWebDriver $driver
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

        return $all;
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
            $this->driver->get($this->baseUrl().'/');
            usleep(800 * 1000);
            $raw = $this->driver->executeScript($script);
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
        try {
            $this->driver->get($url);
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: page load failed', ['url' => $url, 'message' => $e->getMessage()]);

            return [];
        }

        // 지연 로딩(이미지/상품) 렌더 여유.
        usleep(800 * 1000);

        try {
            $raw = $this->driver->executeScript($this->listScript());
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

        return new CrawledProductDto(
            shopCode: $this->getShopCode(),
            externalId: $externalId,
            title: $title,
            subtitle: null,
            brandLabel: null,
            price: $price,
            currency: 'KRW',
            productUrl: $this->resolveUrl((string) ($row['url'] ?? '')),
            categoryCode: $this->refineCategory($title, $fallbackCategory),
            releaseDate: null,
            shippingFee: null,
            imageUrl: $this->resolveImage((string) ($row['img'] ?? '')),
        );
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
