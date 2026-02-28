<?php

use App\Http\Controllers\MyWifeBot\Api\ChatController as MyWifeBotChatController;
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
| MyWifeBot (BASE = /api/my-wife-bot)
|   POST /api/my-wife-bot/chat/init  body: { character_id: "3" }  → session_id, initial_messages
|
*/

Route::prefix('my-wife-bot')->controller(MyWifeBotChatController::class)->group(function () {
    Route::post('chat/init', 'init');
    Route::post('chat/send', 'send');
});

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
