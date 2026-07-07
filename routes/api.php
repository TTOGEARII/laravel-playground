<?php

use App\Http\Controllers\OtakuShop\Api\ProductController as OtakuShopProductController;
use App\Http\Controllers\SubcultureGameInfo\Api\CharacterController as SubcultureCharacterController;
use App\Http\Controllers\SubcultureGameInfo\Api\CodeController as SubcultureCodeController;
use App\Http\Controllers\SubcultureGameInfo\Api\RaidController as SubcultureRaidController;
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

// SubcultureGameInfo (BASE = /api/subculture-game-info)
//   GET  /api/subculture-game-info/codes        ?game, community(0/1), expired(0/1)
//   GET  /api/subculture-game-info/raids        ?game, status(active|upcoming|ended)
//   GET  /api/subculture-game-info/raids/{raid} 보스 정보 + 추천 편성 + 공략글
//   POST /api/subculture-game-info/raids/{raid}/alternative-parties  body: { exclude: [...], page? } — 미보유 제외 실전 편성
//   GET  /api/subculture-game-info/characters   ?game (+meta.growth_schema)
Route::prefix('subculture-game-info')->group(function () {
    Route::get('codes', [SubcultureCodeController::class, 'index']);
    Route::get('raids', [SubcultureRaidController::class, 'index']);
    Route::get('raids/{raid}', [SubcultureRaidController::class, 'show'])->whereNumber('raid');
    Route::post('raids/{raid}/alternative-parties', [SubcultureRaidController::class, 'alternativeParties'])
        ->whereNumber('raid')
        ->middleware('throttle:30,1');
    // 학생별 출전 횟수(블아 전용) — 대체 캐릭터 후보의 실전 채용 빈도
    Route::get('raids/{raid}/student-usage', [SubcultureRaidController::class, 'studentUsage'])
        ->whereNumber('raid')
        ->middleware('throttle:30,1');
    Route::get('characters', [SubcultureCharacterController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| MiniGame API (추가 시 prefix: /api/mini-game/...)
|--------------------------------------------------------------------------
*/
// Route::prefix('mini-game')->group(function () {
//     //
// });
