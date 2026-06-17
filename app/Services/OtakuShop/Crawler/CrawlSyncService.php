<?php

namespace App\Services\OtakuShop\Crawler;

use App\Models\OtakuShop\OtakuCategory;
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
        $now = Carbon::now();

        foreach ($this->groupByProduct($crawledProducts, $shopIds) as $bundle) {
            $product = $this->findOrCreateProduct($bundle, $categoryByCode, $stats);

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
    private function findOrCreateProduct(array $bundle, array $categoryByCode, array &$stats): OtakuProduct
    {
        $dto = $bundle['dto'];
        $product = OtakuProduct::where('ok_product_code', $bundle['key'])->first();

        if ($product === null) {
            $categoryId = $dto->categoryCode ? ($categoryByCode[$dto->categoryCode] ?? null) : null;
            $stats['products_created']++;

            return OtakuProduct::create([
                'ok_product_code' => $bundle['key'],
                'ok_product_title' => $dto->title,
                'ok_product_subtitle' => $dto->subtitle,
                'ok_product_brand_label' => $dto->brandLabel,
                'ok_product_release_date' => $dto->releaseDate,
                'ok_product_active_flg' => true,
                'ok_product_cate_id' => $categoryId,
                'ok_product_image_url' => $dto->imageUrl,
            ]);
        }

        if (! $product->ok_product_image_url && $dto->imageUrl) {
            $product->ok_product_image_url = $dto->imageUrl;
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
