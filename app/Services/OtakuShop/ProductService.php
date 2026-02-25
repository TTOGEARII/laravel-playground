<?php

namespace App\Services\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    /**
     * 필터용 카테고리 목록 (정렬 순).
     */
    public function getCategoriesForFilter(): Collection
    {
        return OtakuCategory::orderBy('ok_category_sort')->get();
    }

    /**
     * 필터용 사용 중인 샵 목록.
     */
    public function getShopsForFilter(): Collection
    {
        return OtakuShop::where('ok_shop_active_flg', true)->orderBy('ok_shop_name')->get();
    }

    /**
     * 상품 목록 조회 (간단 필터 포함).
     */
    public function listProducts(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = OtakuProduct::query()->with([
            'category',
            'offers' => function ($q) {
                $q->where('ok_offer_available_flg', true)->with('shop')->orderBy('ok_offer_price');
            },
        ]);

        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('ok_product_title', 'like', "%{$keyword}%")
                    ->orWhere('ok_product_subtitle', 'like', "%{$keyword}%")
                    ->orWhere('ok_product_brand_label', 'like', "%{$keyword}%");
            });
        }

        if (!empty($filters['brand'])) {
            $query->where('ok_product_brand_label', $filters['brand']);
        }

        if (!empty($filters['shop_ids']) && is_array($filters['shop_ids'])) {
            $shopIds = array_filter(array_map('intval', $filters['shop_ids']));
            if ($shopIds !== []) {
                $query->whereHas('offers', function ($q) use ($shopIds) {
                    $q->whereIn('ok_offer_shop_id', $shopIds);
                });
            }
        }

        if (!empty($filters['active_only'])) {
            $query->where('ok_product_active_flg', true);
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '' && $filters['category_id'] !== null) {
            $categoryId = (int) $filters['category_id'];
            if ($categoryId > 0) {
                $query->where('ok_product_cate_id', $categoryId);
            }
        }

        // sort: price_asc|price_desc|release_desc
        $sort = $filters['sort'] ?? 'price_asc';
        if ($sort === 'release_desc') {
            $query->orderByDesc('ok_product_release_date')->orderByDesc('ok_product_id');
        } else {
            $query->orderByDesc('ok_product_id');
        }

        return $query->paginate($perPage);
    }

    /**
     * 단일 상품 조회.
     */
    public function getProduct(int $id): ?OtakuProduct
    {
        return OtakuProduct::with(['category', 'offers'])->find($id);
    }

    /**
     * 상품 생성.
     */
    public function createProduct(array $data): OtakuProduct
    {
        return OtakuProduct::create($data);
    }

    /**
     * 상품 수정.
     */
    public function updateProduct(int $id, array $data): ?OtakuProduct
    {
        $product = OtakuProduct::find($id);

        if (!$product) {
            return null;
        }

        $product->fill($data);
        $product->save();

        return $product;
    }

    /**
     * 상품 삭제.
     */
    public function deleteProduct(int $id): bool
    {
        $product = OtakuProduct::find($id);

        if (!$product) {
            return false;
        }

        return (bool) $product->delete();
    }

    /**
     * 특정 상품의 최저가 Offer 목록 조회.
     */
    public function getLowestOffersForProduct(int $productId): Collection
    {
        return OtakuOffer::query()
            ->where('ok_offer_product_id', $productId)
            ->where('ok_offer_available_flg', true)
            ->orderBy('ok_offer_price')
            ->get();
    }
}

