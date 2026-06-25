<?php

namespace App\Services\OtakuShop\Crawler\ShopCrawlers;

use App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface;
use App\Services\OtakuShop\Crawler\CrawlerDriver;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use App\Services\OtakuShop\Crawler\ProductNormalizer;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Facades\Http;
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

            // 이 카테고리에서 이미 본 상품번호 집합. cafe24/고도몰 등은 끝 페이지를 넘겨도
            // 빈 배열이 아니라 '이미 본 상품'을 반복해서 돌려준다(실측: page=999 에도 카드 잔존).
            // 따라서 "빈 페이지" 대신 "이 카테고리에 새 상품번호가 0개인 페이지"를 끝 신호로 본다.
            // 전역 $seen 으로 판단하면 카테고리 간 공유 상품 탓에 조기 종료될 수 있어 카테고리별로 분리한다.
            $catSeen = [];

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

                $newInCategory = 0;
                $newGlobal = 0;
                foreach ($rows as $row) {
                    // 끝 페이지 판정은 DTO 필터(제외 키워드/가격 누락 등) 전의 원본 상품번호로 한다.
                    // 제외 상품만 있는 페이지에서 0으로 잡혀 다음 페이지를 건너뛰는 것을 막기 위함.
                    $externalId = trim((string) ($row['id'] ?? ''));
                    if ($externalId !== '') {
                        $rowKey = $this->getShopCode().':'.$externalId;
                        if (! isset($catSeen[$rowKey])) {
                            $catSeen[$rowKey] = true;
                            $newInCategory++;
                        }
                    }

                    $dto = $this->toDto($row, $category);
                    if ($dto === null) {
                        continue;
                    }
                    $key = $this->getShopCode().':'.$dto->externalId;
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $all[] = $dto;
                        $newGlobal++;
                    }
                }

                // 중복 카테고리 스킵: 첫 페이지가 전부 '이미 다른 카테고리에서 수집한 상품'이면
                // (동일 상품을 다른 정렬/경로로 보여주는 중복 뷰 카테고리) 이 카테고리 전체를 건너뛴다.
                // 신상위주 수집에서 같은 카탈로그를 여러 번 도는 낭비를 막는다.
                if ($page === 1 && $newGlobal === 0) {
                    break;
                }

                // 이 페이지가 이 카테고리에 새 상품번호를 하나도 더하지 않았다면(=끝 페이지를
                // 넘겨 반복 응답을 받는 중) 더 요청하지 않고 다음 카테고리로 넘어간다.
                if ($newInCategory === 0) {
                    break;
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
        // HTTP 모드 샵(animate 등)은 상세 HTML 을 받아 parseDetail() 로 파싱한다(Selenium 불필요).
        if ($this->usesHttpFetch()) {
            $html = $this->httpGet($url);

            return $html === null ? null : $this->parseDetail($html);
        }

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
        $paths = $this->discoverCategoryPaths();
        if ($paths === []) {
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
     * 전량 크롤 카테고리 경로 발견. 기본은 Selenium(categoryDiscoveryScript)으로 메뉴를 읽는다.
     * HTTP 모드 샵(Cafe24)은 이 메서드를 오버라이드해 HTTP+DOM 파싱으로 대체한다.
     *
     * @return array<int, string>
     */
    protected function discoverCategoryPaths(): array
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

        return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
    }

    /**
     * 카테고리 발견용 브라우저 JS (리스트 경로 배열 JSON 반환). 없으면 null → config 사용.
     */
    protected function categoryDiscoveryScript(): ?string
    {
        return null;
    }

    /**
     * HTTP 직접 패치 모드 여부. true 면 리스트/카테고리/상세 수집을 Selenium 대신 HTTP+DOM 파싱으로 한다.
     * 서버렌더링(cafe24/godo 등) 샵은 Chrome 없이 더 빠르고, Selenium 장애와 무관하게 동작한다.
     * (runner 가 이 값으로 드라이버 start 를 건너뛸지 판단하므로 public.)
     */
    public function usesHttpFetch(): bool
    {
        return false;
    }

    /**
     * 공통 HTTP GET (브라우저 헤더 + 재시도). 실패 시 null.
     */
    protected function httpGet(string $url): ?string
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ko-KR,ko;q=0.9',
            ])->timeout(20)->retry(2, 500)->get($url);
        } catch (\Throwable $e) {
            Log::warning('OtakuShop Crawler: http fetch failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            Log::warning('OtakuShop Crawler: http fetch non-2xx', ['url' => $url, 'status' => $res->status()]);

            return null;
        }

        return $res->body();
    }

    /**
     * HTTP 모드 샵이 리스트 HTML 을 상품 행 배열로 파싱한다(listScript 의 PHP 대응).
     * 반환 행 형식: ['id','title','price','url','img','soldout','maker','release'].
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseListRows(string $html): array
    {
        return [];
    }

    /**
     * HTTP 모드 샵이 상세 HTML 에서 {barcode, maker, soldout} 를 파싱한다(detailScript 의 PHP 대응).
     *
     * @return array<string, mixed>|null
     */
    protected function parseDetail(string $html): ?array
    {
        return null;
    }

    /**
     * HTML 문자열을 UTF-8 로 로드해 DOMXPath 를 만든다. 빈/실패 시 null.
     */
    protected function loadXPath(string $html): ?\DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? new \DOMXPath($doc) : null;
    }

    /**
     * context 하위에서 XPath 첫 노드. 없으면 null.
     */
    protected function firstNode(\DOMXPath $xp, string $query, \DOMNode $context): ?\DOMNode
    {
        $nodes = $xp->query($query, $context);

        return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : null;
    }

    /**
     * 연속 공백을 한 칸으로 정리하고 trim.
     */
    protected function cleanText(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    }

    /**
     * 한 리스트 페이지를 열고 listScript() 로 상품 배열을 받아온다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPage(string $url): array
    {
        // 서버렌더링 샵(cafe24 등)은 Selenium 없이 HTTP 로 HTML 을 받아 파싱한다(훨씬 빠르고 Chrome 부담 없음).
        if ($this->usesHttpFetch()) {
            $html = $this->httpGet($url);

            return $html === null ? [] : $this->parseListRows($html);
        }

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
