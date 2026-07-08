<?php

use App\Http\Controllers\OtakuShop\Api\ProductController as OtakuShopProductController;
use App\Http\Controllers\SubcultureGameInfo\Api\AttributePartyController as SubcultureAttributePartyController;
use App\Http\Controllers\SubcultureGameInfo\Api\CharacterController as SubcultureCharacterController;
use App\Http\Controllers\SubcultureGameInfo\Api\CodeController as SubcultureCodeController;
use App\Http\Controllers\SubcultureGameInfo\Api\GuidePostController as SubcultureGuidePostController;
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
    // 미보유 캐릭터 대체 후보 Gemini 추천 — body: { character_key, owned: [...] }
    // Gemini 토큰 비용이 드는 호출이라 스로틀을 빡빡하게(분당 10) + 서비스단 1일 캐시
    Route::post('raids/{raid}/substitute-recommendations', [SubcultureRaidController::class, 'substituteRecommendations'])
        ->whereNumber('raid')
        ->middleware('throttle:10,1');
    Route::get('characters', [SubcultureCharacterController::class, 'index']);
    // 속성(성격)별 추천 조합(트릭컬) — ?game=trickcal
    Route::get('attribute-parties', [SubcultureAttributePartyController::class, 'index']);
    // 게임 단위 최근 공략글 피드 — ?game=&limit=
    Route::get('guide-posts', [SubcultureGuidePostController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| MiniGame API (추가 시 prefix: /api/mini-game/...)
|--------------------------------------------------------------------------
*/
// Route::prefix('mini-game')->group(function () {
//     //
// });
