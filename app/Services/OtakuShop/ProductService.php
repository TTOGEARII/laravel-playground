<?php

namespace App\Services\OtakuShop;

use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    /**
     * 상품 목록 조회 (간단 필터 포함).
     */
    public function listProducts(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = OtakuProduct::query()->with('offers');

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

        if (!empty($filters['active_only'])) {
            $query->where('ok_product_active_flg', true);
        }

        return $query->orderByDesc('ok_product_id')->paginate($perPage);
    }

    /**
     * 단일 상품 조회.
     */
    public function getProduct(int $id): ?OtakuProduct
    {
        return OtakuProduct::with('offers')->find($id);
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

