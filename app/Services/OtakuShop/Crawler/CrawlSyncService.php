<?php

namespace App\Services\OtakuShop\Crawler;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use Illuminate\Support\Carbon;

/**
 * 크롤 결과를 otaku_shop, otaku_category, otaku_product, otaku_offer 에 동기화.
 * MVC: Model 사용, 비즈니스 로직은 서비스에 두고, Console에서 이 서비스만 호출.
 */
class CrawlSyncService
{
    public function __construct(
        private ProductNormalizer $normalizer
    ) {}

    /**
     * 1. config 의 샵 정보를 otaku_shop 에 insert (이미 있으면 스킵).
     */
    public function syncShops(): void
    {
        $shops = config('otaku-crawler.shops', []);
        foreach ($shops as $row) {
            OtakuShop::firstOrCreate(
                ['ok_shop_code' => $row['ok_shop_code']],
                [
                    'ok_shop_name' => $row['ok_shop_name'],
                    'ok_shop_url' => $row['ok_shop_url'] ?? null,
                    'ok_shop_active_flg' => true,
                ]
            );
        }
    }

    /**
     * 2. 공통 카테고리를 otaku_category 에 insert (이미 있으면 스킵).
     */
    public function syncCategories(): void
    {
        $categories = config('otaku-crawler.categories', []);
        foreach ($categories as $row) {
            OtakuCategory::firstOrCreate(
                ['ok_category_code' => $row['ok_category_code']],
                [
                    'ok_category_label' => $row['ok_category_label'],
                    'ok_category_sort' => $row['ok_category_sort'] ?? 0,
                ]
            );
        }
    }

    /**
     * 2-2. IP(작품) 사전을 otaku_ip 에 insert (이미 있으면 스킵).
     * config 의 ip_aliases 표준토큰을 코드로 쓴다. 표시 이름(label)은 별도 지정이 없으면 코드와 동일.
     */
    public function syncIps(): void
    {
        $labels = config('otaku-crawler.product_match.ip_labels', []);
        $sort = 0;
        foreach (array_keys(config('otaku-crawler.product_match.ip_aliases', [])) as $code) {
            $sort += 10;
            OtakuIp::firstOrCreate(
                ['ok_ip_code' => $code],
                [
                    'ok_ip_label' => $labels[$code] ?? $code,
                    'ok_ip_sort' => $sort,
                ]
            );
        }
    }

    /**
     * 3·4. 크롤된 상품 목록을 정규화 키로 묶어 otaku_product/otaku_offer 에 동기화한다.
     *
     * 가격비교의 핵심: 같은 정규화 키 = 동일 상품. 한 상품에 대해 쇼핑몰별로 오퍼는 "정확히 1건"이며
     * 같은 샵이 (일반/특전 등) 여러 변형을 올린 경우 그 중 최저가만 그 샵의 오퍼로 남긴다.
     * 이렇게 해야 쇼핑몰 간 가격비교가 같은 기준(샵별 최저가)으로 이뤄진다.
     *
     * @param  array<int, CrawledProductDto>  $crawledProducts
     * @param  bool  $incremental  현재 오퍼는 (상품,샵) 단위로 항상 upsert 되므로 동작 차이는 없고,
     *                             증분/전체 호출 호환을 위해 시그니처만 유지한다.
     * @return array{products_created:int, products_matched:int, offers_created:int, offers_updated:int}
     */
    public function syncProductsAndOffers(array $crawledProducts, bool $incremental = true): array
    {
        $stats = ['products_created' => 0, 'products_matched' => 0, 'offers_created' => 0, 'offers_updated' => 0];
        $shopIds = OtakuShop::pluck('ok_shop_id', 'ok_shop_code')->all();
        $categoryByCode = OtakuCategory::pluck('ok_category_id', 'ok_category_code')->all();
        $ipIdByCode = OtakuIp::pluck('ok_ip_id', 'ok_ip_code')->all();
        $now = Carbon::now();

        foreach ($this->groupByProduct($crawledProducts, $shopIds) as $bundle) {
            $product = $this->findOrCreateProduct($bundle, $categoryByCode, $ipIdByCode, $stats);

            foreach ($bundle['offers'] as $shopId => $dto) {
                $this->upsertOffer($product, (int) $shopId, $dto, $now, $stats);
            }
        }

        $this->updateLowestPriceFlags();

        return $stats;
    }

    /**
     * 크롤 결과를 정규화 키(동일 상품)로 묶는다.
     * 같은 (상품, 샵)이면 최저가 DTO 하나만 유지해 샵별 오퍼 중복을 제거한다.
     *
     * @param  array<int, CrawledProductDto>  $crawledProducts
     * @param  array<string, int>  $shopIds  shop_code => ok_shop_id
     * @return array<string, array{key: string, dto: CrawledProductDto, offers: array<int, CrawledProductDto>}>
     */
    private function groupByProduct(array $crawledProducts, array $shopIds): array
    {
        $bundles = [];

        foreach ($crawledProducts as $dto) {
            $shopId = $shopIds[$dto->shopCode] ?? null;
            if ($shopId === null) {
                continue;
            }

            $key = $this->normalizer->normalizeKey($dto->title, $dto->brandLabel);
            $bundles[$key] ??= ['key' => $key, 'dto' => $dto, 'offers' => []];

            $existing = $bundles[$key]['offers'][$shopId] ?? null;
            if ($existing === null || $dto->price < $existing->price) {
                $bundles[$key]['offers'][$shopId] = $dto;
            }
        }

        return $bundles;
    }

    /**
     * 정규화 키로 상품을 찾거나 생성. 기존 상품에 이미지가 비어 있으면 채워준다.
     *
     * @param  array{key: string, dto: CrawledProductDto, offers: array<int, CrawledProductDto>}  $bundle
     * @param  array<string, int>  $categoryByCode
     */
    private function findOrCreateProduct(array $bundle, array $categoryByCode, array $ipIdByCode, array &$stats): OtakuProduct
    {
        $dto = $bundle['dto'];
        // 발매(예정)일·IP는 제목에서 파싱한다(크롤 단계가 아니라 여기서 분류).
        $releaseDate = $dto->releaseDate ?? $this->normalizer->extractReleaseDate($dto->title);
        $ipCode = $this->normalizer->extractIpCode($dto->title);
        $ipId = $ipCode !== null ? ($ipIdByCode[$ipCode] ?? null) : null;
        $product = OtakuProduct::where('ok_product_code', $bundle['key'])->first();

        if ($product === null) {
            $categoryId = $dto->categoryCode ? ($categoryByCode[$dto->categoryCode] ?? null) : null;
            $stats['products_created']++;

            return OtakuProduct::create([
                'ok_product_code' => $bundle['key'],
                'ok_product_title' => $dto->title,
                'ok_product_subtitle' => $dto->subtitle,
                'ok_product_brand_label' => $dto->brandLabel,
                'ok_product_release_date' => $releaseDate,
                'ok_product_active_flg' => true,
                'ok_product_cate_id' => $categoryId,
                'ok_product_ip_id' => $ipId,
                'ok_product_image_url' => $dto->imageUrl,
            ]);
        }

        // 기존 상품: 비어 있는 분류값을 채워준다(재크롤로 점진 보강).
        if (! $product->ok_product_image_url && $dto->imageUrl) {
            $product->ok_product_image_url = $dto->imageUrl;
        }
        if ($product->ok_product_release_date === null && $releaseDate !== null) {
            $product->ok_product_release_date = $releaseDate;
        }
        if ($product->ok_product_ip_id === null && $ipId !== null) {
            $product->ok_product_ip_id = $ipId;
        }
        if ($product->isDirty()) {
            $product->save();
        }
        $stats['products_matched']++;

        return $product;
    }

    /**
     * (상품, 샵) 단위로 오퍼를 upsert. 같은 조합이 이미 있으면 가격/URL 등을 갱신한다.
     */
    private function upsertOffer(OtakuProduct $product, int $shopId, CrawledProductDto $dto, Carbon $now, array &$stats): void
    {
        $offerData = [
            'ok_offer_product_id' => $product->ok_product_id,
            'ok_offer_shop_id' => $shopId,
            'ok_offer_external_id' => $dto->externalId,
            'ok_offer_currency' => $dto->currency,
            'ok_offer_price' => $dto->price,
            'ok_offer_available_flg' => true,
            'ok_offer_external_url' => $dto->productUrl,
            'ok_offer_collected_dt' => $now,
        ];

        $offer = OtakuOffer::where('ok_offer_product_id', $product->ok_product_id)
            ->where('ok_offer_shop_id', $shopId)
            ->first();

        if ($offer !== null) {
            $offer->update($offerData);
            $stats['offers_updated']++;
        } else {
            OtakuOffer::create($offerData);
            $stats['offers_created']++;
        }
    }

    /**
     * 상품별 최저가 플래그 갱신.
     */
    private function updateLowestPriceFlags(): void
    {
        OtakuOffer::query()->update(['ok_offer_lowest_flg' => false]);
        $minPrices = OtakuOffer::query()
            ->where('ok_offer_available_flg', true)
            ->selectRaw('ok_offer_product_id, MIN(ok_offer_price) as min_price')
            ->groupBy('ok_offer_product_id')
            ->pluck('min_price', 'ok_offer_product_id');

        foreach ($minPrices as $productId => $minPrice) {
            OtakuOffer::where('ok_offer_product_id', $productId)
                ->where('ok_offer_price', $minPrice)
                ->update(['ok_offer_lowest_flg' => true]);
        }
    }
}
