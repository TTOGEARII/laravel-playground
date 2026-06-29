<?php

use App\Http\Controllers\SubcultureGameInfo\MainController;
use App\Http\Controllers\SubcultureGameInfo\RedemptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MainController::class, 'index'])->name('subculture-game-info.index');

// 교환 완료 체크 (로그인 사용자 전용 — 세션 인증). 비로그인은 클라이언트 localStorage 사용.
Route::middleware('auth')->controller(RedemptionController::class)->group(function () {
    Route::get('redemptions', 'index')->name('subculture-game-info.redemptions.index');
    Route::post('redemptions', 'store')->name('subculture-game-info.redemptions.store');
    Route::delete('redemptions/{code}', 'destroy')
        ->whereNumber('code')
        ->name('subculture-game-info.redemptions.destroy');
});
