<?php

use App\Http\Controllers\SubcultureGameInfo\CodePageController;
use App\Http\Controllers\SubcultureGameInfo\HubController;
use App\Http\Controllers\SubcultureGameInfo\InfoPageController;
use App\Http\Controllers\SubcultureGameInfo\RedemptionController;
use App\Http\Controllers\SubcultureGameInfo\UserCharacterController;
use App\Http\Controllers\SubcultureGameInfo\UserSubstituteController;
use Illuminate\Support\Facades\Route;

// 허브 랜딩 — 리딤코드 / 정보검색 2선택 진입
Route::get('/', [HubController::class, 'index'])->name('subculture-game-info.index');

// 리딤코드 (기존 index 를 /codes 로 이동)
Route::get('codes', [CodePageController::class, 'index'])->name('subculture-game-info.codes');

// 정보검색 — mollulog 스타일 대시보드(진행중·모집중·레이드·공략 + 미래시·학정보 + AI 물어보기)
Route::get('info', [InfoPageController::class, 'index'])->name('subculture-game-info.info');

// 옛 URL 호환 — /raids 는 정보검색으로 통합됨(북마크·PWA 바로가기 보전)
Route::redirect('raids', 'info', 301)->name('subculture-game-info.raids.index');

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

// 내 대체 캐릭터 매핑 (미보유 → 내 보유, 로그인 전용). 비로그인은 localStorage 사용.
Route::middleware('auth')->controller(UserSubstituteController::class)->prefix('my-substitutes')->group(function () {
    Route::get('/', 'index')->name('subculture-game-info.my-substitutes.index');
    Route::put('/', 'update')->name('subculture-game-info.my-substitutes.update');
    Route::delete('/', 'destroy')->name('subculture-game-info.my-substitutes.destroy');
});
