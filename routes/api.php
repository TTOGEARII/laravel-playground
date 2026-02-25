<?php

use App\Http\Controllers\OtakuShop\Api\ProductController as OtakuShopProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (메인 API) - prefix 'api' 자동 적용
|--------------------------------------------------------------------------
|
| Otaku Shop (BASE = /api/otaku-shop)
|   GET /api/otaku-shop/products   ?page, per_page, keyword, category_id, shop_id[], sort
|   GET /api/otaku-shop/categories
|   GET /api/otaku-shop/shops
|
*/

Route::prefix('otaku-shop')->controller(OtakuShopProductController::class)->group(function () {
    Route::get('products', 'index');
    Route::get('categories', 'categories');
    Route::get('shops', 'shops');
});

/*
|--------------------------------------------------------------------------
| MiniGame API (추가 시 prefix: /api/mini-game/...)
|--------------------------------------------------------------------------
*/
// Route::prefix('mini-game')->group(function () {
//     //
// });
