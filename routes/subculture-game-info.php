<?php

use App\Http\Controllers\SubcultureGameInfo\MainController;
use App\Http\Controllers\SubcultureGameInfo\RaidPageController;
use App\Http\Controllers\SubcultureGameInfo\RedemptionController;
use App\Http\Controllers\SubcultureGameInfo\UserCharacterController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MainController::class, 'index'])->name('subculture-game-info.index');

// 레이드 정보 통합(보스 일정·추천 편성·공략글·내 캐릭터) — Vue 페이지
Route::get('raids', [RaidPageController::class, 'index'])->name('subculture-game-info.raids.index');

// 교환 완료 체크 (로그인 사용자 전용 — 세션 인증). 비로그인은 클라이언트 localStorage 사용.
Route::middleware('auth')->controller(RedemptionController::class)->group(function () {
    Route::get('redemptions', 'index')->name('subculture-game-info.redemptions.index');
    Route::post('redemptions', 'store')->name('subculture-game-info.redemptions.store');
    Route::delete('redemptions/{code}', 'destroy')
        ->whereNumber('code')
        ->name('subculture-game-info.redemptions.destroy');
});

// 내 캐릭터 풀 (로그인 사용자 전용 — 세션 인증). 비로그인은 클라이언트 localStorage 사용.
Route::middleware('auth')->controller(UserCharacterController::class)->prefix('my-characters')->group(function () {
    Route::get('/', 'index')->name('subculture-game-info.my-characters.index');
    Route::get('export', 'export')->name('subculture-game-info.my-characters.export');
    Route::post('import', 'import')
        ->middleware('throttle:10,1')
        ->name('subculture-game-info.my-characters.import');
    Route::put('{character}', 'update')
        ->whereNumber('character')
        ->name('subculture-game-info.my-characters.update');
    Route::delete('{character}', 'destroy')
        ->whereNumber('character')
        ->name('subculture-game-info.my-characters.destroy');
});
