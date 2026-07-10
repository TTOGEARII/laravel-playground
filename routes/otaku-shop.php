<?php

use App\Http\Controllers\OtakuShop\MainController;
use App\Http\Controllers\OtakuShop\WishController;

Route::get('/', [MainController::class, 'index'])->name('otaku-shop.index');

// 찜(재입고 알림) — 로그인 전용, 세션 인증이라 web 그룹
Route::middleware('auth')->group(function () {
    Route::get('/wishes', [WishController::class, 'index'])->name('otaku-shop.wishes.index');
    Route::post('/wishes', [WishController::class, 'store'])
        ->middleware('throttle:30,1')->name('otaku-shop.wishes.store');
    Route::delete('/wishes/{productId}', [WishController::class, 'destroy'])
        ->middleware('throttle:30,1')->name('otaku-shop.wishes.destroy');
});
