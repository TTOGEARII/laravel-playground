<?php

namespace App\Http\Controllers\OtakuShop;

use App\Http\Controllers\Controller;
use App\Models\OtakuShop\OtakuWish;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 오타쿠샵 찜 — 로그인 전용(라우트 auth 미들웨어). 재입고 웹푸시 알림의 대상 목록이 된다.
 */
class WishController extends Controller
{
    /** 내 찜 상품 ID 목록. */
    public function index(Request $request): JsonResponse
    {
        $ids = OtakuWish::where('user_id', $request->user()->id)
            ->pluck('ok_wish_product_id');

        return response()->json(['data' => $ids]);
    }

    /** 찜 등록(멱등). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:otaku_product,ok_product_id'],
        ]);

        OtakuWish::firstOrCreate([
            'user_id' => $request->user()->id,
            'ok_wish_product_id' => (int) $validated['product_id'],
        ]);

        return response()->json(['data' => ['wished' => true]]);
    }

    /** 찜 해제. */
    public function destroy(Request $request, int $productId): JsonResponse
    {
        OtakuWish::where('user_id', $request->user()->id)
            ->where('ok_wish_product_id', $productId)
            ->delete();

        return response()->json(['data' => ['wished' => false]]);
    }
}
