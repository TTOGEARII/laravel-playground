<?php

namespace App\Services\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
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
     * 필터용 IP(작품) 목록. 실제 상품이 1개 이상 붙은 IP만, 상품 많은 순으로.
     */
    public function getIpsForFilter(): Collection
    {
        return OtakuIp::query()
            ->withCount('products')
            ->whereHas('products')  // 상품이 1개 이상 붙은 IP만
            ->orderByDesc('products_count')
            ->get();
    }

    /**
     * 상품 목록 조회 (간단 필터 포함).
     */
    public function listProducts(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = OtakuProduct::query()->with([
            'category',
            'ip',
            'offers' => function ($q) {
                $q->where('ok_offer_available_flg', true)->with('shop')->orderBy('ok_offer_price');
            },
        ]);

        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('ok_product_title', 'like', "%{$keyword}%")
                    ->orWhere('ok_product_subtitle', 'like', "%{$keyword}%")
                    ->orWhere('ok_product_brand_label', 'like', "%{$keyword}%");
            });
        }

        if (! empty($filters['brand'])) {
            $query->where('ok_product_brand_label', $filters['brand']);
        }

        if (! empty($filters['shop_ids']) && is_array($filters['shop_ids'])) {
            $shopIds = array_filter(array_map('intval', $filters['shop_ids']));
            if ($shopIds !== []) {
                $query->whereHas('offers', function ($q) use ($shopIds) {
                    $q->whereIn('ok_offer_shop_id', $shopIds);
                });
            }
        }

        if (! empty($filters['active_only'])) {
            $query->where('ok_product_active_flg', true);
        }

        // 가격비교 가능(2개 이상 쇼핑몰에 판매 중 오퍼가 있는) 상품만.
        if (! empty($filters['compared_only'])) {
            $query->whereHas('offers', function ($q) {
                $q->where('ok_offer_available_flg', true);
            }, '>=', 2);
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '' && $filters['category_id'] !== null) {
            $categoryId = (int) $filters['category_id'];
            if ($categoryId > 0) {
                $query->where('ok_product_cate_id', $categoryId);
            }
        }

        if (isset($filters['ip_id']) && $filters['ip_id'] !== '' && $filters['ip_id'] !== null) {
            $ipId = (int) $filters['ip_id'];
            if ($ipId > 0) {
                $query->where('ok_product_ip_id', $ipId);
            }
        }

        // 발매(예정)일이 있는 상품만(예: 예약/발매예정 모아보기).
        if (! empty($filters['has_release'])) {
            $query->whereNotNull('ok_product_release_date');
        }

        // 발매예정(오늘 이후 발매일) 상품만.
        if (! empty($filters['upcoming'])) {
            $query->whereNotNull('ok_product_release_date')
                ->whereDate('ok_product_release_date', '>=', now()->toDateString());
        }

        // sort: price_asc|price_desc(판매중 오퍼 최저가 기준)|release_desc(최신 발매순)|release_asc(발매 임박순)
        $sort = $filters['sort'] ?? 'price_asc';
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            // 가격은 offer 테이블에 있으므로 판매중 오퍼의 최저가를 상관 서브쿼리로 끌어와 정렬한다.
            $minPriceSub = OtakuOffer::query()
                ->selectRaw('MIN(ok_offer_price)')
                ->whereColumn('ok_offer_product_id', 'otaku_product.ok_product_id')
                ->where('ok_offer_available_flg', true);

            $query->select('otaku_product.*')
                ->selectSub($minPriceSub, 'min_offer_price')
                ->orderByRaw('min_offer_price IS NULL')  // 판매중 오퍼 없는 상품(가격 없음)은 뒤로
                ->orderBy('min_offer_price', $sort === 'price_asc' ? 'asc' : 'desc')
                ->orderByDesc('ok_product_id');
        } elseif ($sort === 'release_desc') {
            $query->orderByRaw('ok_product_release_date IS NULL')  // 발매일 없는 건 뒤로
                ->orderByDesc('ok_product_release_date')->orderByDesc('ok_product_id');
        } elseif ($sort === 'release_asc') {
            $query->orderByRaw('ok_product_release_date IS NULL')
                ->orderBy('ok_product_release_date')->orderByDesc('ok_product_id');
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

        if (! $product) {
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

        if (! $product) {
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
