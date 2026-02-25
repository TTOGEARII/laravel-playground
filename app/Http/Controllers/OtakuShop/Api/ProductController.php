<?php

namespace App\Http\Controllers\OtakuShop\Api;

use App\Http\Controllers\Controller;
use App\Services\OtakuShop\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * 상품 목록 (페이지네이션, 필터).
     * 쿼리: page, per_page, keyword, category_id (ok_product_cate_id), shop_id[], sort (price_asc|price_desc|release_desc)
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'active_only' => true,
            'keyword' => $request->input('keyword'),
            'brand' => $request->input('brand'),
            'category_id' => $request->input('category_id'),
            'sort' => $request->input('sort', 'price_asc'),
        ];
        $shopIds = $request->input('shop_id', []);
        if (is_array($shopIds) && $shopIds !== []) {
            $filters['shop_ids'] = $shopIds;
        } elseif (is_string($shopIds) && $shopIds !== '') {
            $filters['shop_ids'] = [$shopIds];
        }
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);

        $perPage = min((int) $request->input('per_page', 15), 50);
        $paginator = $this->productService->listProducts($filters, $perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * 필터용 카테고리 목록.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => $this->productService->getCategoriesForFilter(),
        ]);
    }

    /**
     * 필터용 샵 목록.
     */
    public function shops(): JsonResponse
    {
        return response()->json([
            'data' => $this->productService->getShopsForFilter(),
        ]);
    }
}
