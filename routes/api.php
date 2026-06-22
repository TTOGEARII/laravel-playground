<?php

use App\Http\Controllers\OtakuShop\Api\ProductController as OtakuShopProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (메인 API) - prefix 'api' 자동 적용
|--------------------------------------------------------------------------
|
| Otaku Shop (BASE = /api/otaku-shop)
|   GET /api/otaku-shop/products   ?page, per_page, keyword, category_id, ip_id, shop_id[], has_release, sort
|   GET /api/otaku-shop/categories
|   GET /api/otaku-shop/ips
|   GET /api/otaku-shop/shops
|
| MyWifeBot (BASE = /api/my-wife-bot)
|   POST /api/my-wife-bot/chat/init  body: { character_id: "3" }  → session_id, initial_messages
|
*/

// MyWifeBot 채팅 API는 세션 인증이 필요해 routes/web.php(web 그룹)로 이전됨.

Route::prefix('otaku-shop')->controller(OtakuShopProductController::class)->group(function () {
    Route::get('products', 'index');
    Route::get('categories', 'categories');
    Route::get('ips', 'ips');
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
