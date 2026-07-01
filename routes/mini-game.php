<?php

use App\Http\Controllers\MiniGame\MainController;
use App\Http\Controllers\MiniGame\ScoreController;

Route::get('/', [MainController::class, 'index'])->name('mini-game.index');

// 점수 랭킹 API (내가 만든 게임 공통) — 등록은 스팸 방지 쓰로틀 적용
Route::get('rankings', [ScoreController::class, 'all'])->name('mini-game.rankings');
Route::post('scores', [ScoreController::class, 'store'])->middleware('throttle:20,1')->name('mini-game.scores.store');

Route::prefix('vampire-survival')->group(function () {
    Route::get('/', [MainController::class, 'vampireSurvival'])->name('mini-game.vampire-survival.index');
});

Route::prefix('tetris')->group(function () {
    Route::get('/', [MainController::class, 'tetris'])->name('mini-game.tetris.index');
});

Route::prefix('doom')->group(function () {
    Route::get('/', [MainController::class, 'doom'])->name('mini-game.doom.index');
});
