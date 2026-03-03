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
     * 3·4. 크롤된 상품 목록으로 otaku_product 찾거나 생성 후, otaku_offer upsert.
     * - incremental: true 이면 기존 offer(shop_id + external_id) 있으면 가격/URL만 업데이트, 없으면 새로 insert.
     * - incremental: false 이면 상품/오퍼 모두 upsert (전체 재수집 시).
     */
    public function syncProductsAndOffers(array $crawledProducts, bool $incremental = true): array
    {
        $stats = ['products_created' => 0, 'products_matched' => 0, 'offers_created' => 0, 'offers_updated' => 0];
        $shopIds = OtakuShop::pluck('ok_shop_id', 'ok_shop_code')->all();
        $categoryByCode = OtakuCategory::pluck('ok_category_id', 'ok_category_code')->all();
        $now = Carbon::now();

        foreach ($crawledProducts as $dto) {
            $shopId = $shopIds[$dto->shopCode] ?? null;
            if ($shopId === null) {
                continue;
            }

            $productKey = $this->normalizer->normalizeKey($dto->title, $dto->brandLabel);
            $product = OtakuProduct::where('ok_product_code', $productKey)->first();
            if ($product === null) {
                $categoryId = $dto->categoryCode ? ($categoryByCode[$dto->categoryCode] ?? null) : null;
                $product = OtakuProduct::create([
                    'ok_product_code' => $productKey,
                    'ok_product_title' => $dto->title,
                    'ok_product_subtitle' => $dto->subtitle,
                    'ok_product_brand_label' => $dto->brandLabel,
                    'ok_product_release_date' => $dto->releaseDate,
                    'ok_product_active_flg' => true,
                    'ok_product_cate_id' => $categoryId,
                    'ok_product_image_url' => $dto->imageUrl,
                ]);
                $stats['products_created']++;
            } else {
                // 기존 상품에 이미지가 비어 있고, 새 DTO 에 이미지가 있으면 채워준다.
                if (! $product->ok_product_image_url && $dto->imageUrl) {
                    $product->ok_product_image_url = $dto->imageUrl;
                    $product->save();
                }
                $stats['products_matched']++;
            }

            $offer = null;
            if ($incremental && $dto->externalId !== '') {
                $offer = OtakuOffer::where('ok_offer_shop_id', $shopId)
                    ->where('ok_offer_external_id', $dto->externalId)
                    ->first();
            }

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

            if ($offer !== null) {
                $offer->update($offerData);
                $stats['offers_updated']++;
            } else {
                OtakuOffer::create($offerData);
                $stats['offers_created']++;
            }
        }

        $this->updateLowestPriceFlags();

        return $stats;
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
